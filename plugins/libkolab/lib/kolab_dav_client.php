<?php

/**
 * A *DAV client.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2022, Apheleia IT AG <contact@apheleia-it.ch>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_dav_client
{
    public $url;

    protected $user;
    protected $password;
    protected $rc;
    protected $responseHeaders = [];

    /**
     * Object constructor
     */
    public function __construct($url)
    {
        $this->rc = rcube::get_instance();

        $parsedUrl = parse_url($url);

        if (!empty($parsedUrl['user']) && !empty($parsedUrl['pass'])) {
            $this->user     = rawurldecode($parsedUrl['user']);
            $this->password = rawurldecode($parsedUrl['pass']);

            $url = str_replace(rawurlencode($this->user) . ':' . rawurlencode($this->password) . '@', '', $url);
        }
        else {
            $this->user     = $this->rc->user->get_username();
            $this->password = $this->rc->decrypt($_SESSION['password']);
        }

        $this->url = $url;
    }

    /**
     * Execute HTTP request to a DAV server
     */
    protected function request($path, $method, $body = '', $headers = [])
    {
        $rcube = rcube::get_instance();
        $debug = (array) $rcube->config->get('dav_debug');

        $request_config = [
            'store_body'       => true,
            'follow_redirects' => true,
        ];

        $this->responseHeaders = [];

        if ($path && ($rootPath = parse_url($this->url, PHP_URL_PATH)) && strpos($path, $rootPath) === 0) {
            $path = substr($path, strlen($rootPath));
        }

        try {
            $request = $this->initRequest($this->url . $path, $method, $request_config);

            $request->setAuth($this->user, $this->password);

            if ($body) {
                $request->setBody($body);
                $request->setHeader(['Content-Type' => 'application/xml; charset=utf-8']);
            }

            if (!empty($headers)) {
                $request->setHeader($headers);
            }

            if ($debug) {
                rcube::write_log('dav', "C: {$method}: " . (string) $request->getUrl()
                     . "\n" . $this->debugBody($body, $request->getHeaders()));
            }

            $response = $request->send();

            $body = $response->getBody();
            $code = $response->getStatus();

            if ($debug) {
                rcube::write_log('dav', "S: [{$code}]\n" . $this->debugBody($body, $response->getHeader()));
            }

            if ($code >= 300) {
                throw new Exception("DAV Error ($code):\n{$body}");
            }

            $this->responseHeaders = $response->getHeader();

            return $this->parseXML($body);
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
            return false;
        }
    }

    /**
     * Discover DAV folders of specified type on the server
     */
    public function discover($component = 'VEVENT')
    {
        $roots = [
            'VEVENT' => 'calendars',
            'VTODO' => 'calendars',
            'VCARD' => 'addressbooks',
        ];

        $path = parse_url($this->url, PHP_URL_PATH);

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:">'
                . '<d:prop>'
                    . '<d:current-user-principal />'
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request('/' . $roots[$component], 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        $elements = $response->getElementsByTagName('response');

        foreach ($elements as $element) {
            foreach ($element->getElementsByTagName('prop') as $prop) {
                $principal_href = $prop->nodeValue;
                break;
            }
        }

        if ($path && strpos($principal_href, $path) === 0) {
            $principal_href = substr($principal_href, strlen($path));
        }

        $homes = [
            'VEVENT' => 'calendar-home-set',
            'VTODO' => 'calendar-home-set',
            'VCARD' => 'addressbook-home-set',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component] . '">'
                . '<d:prop>'
                    . '<c:' . $homes[$component] . ' />'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($principal_href, 'PROPFIND', $body);

        $elements = $response->getElementsByTagName('response');

        foreach ($elements as $element) {
            foreach ($element->getElementsByTagName('prop') as $prop) {
                $root_href = $prop->nodeValue;
                break;
            }
        }

        if (!empty($root_href)) {
            if ($path && strpos($root_href, $path) === 0) {
                $root_href = substr($root_href, strlen($path));
            }
        }
        else {
            // Kolab iRony's calendar root
            $root_href = '/' . $roots[$component] . '/' . rawurlencode($this->user);
        }

        if ($component == 'VCARD') {
            $add_ns = '';
            $add_props = '';
        }
        else {
            $add_ns = ' xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/"';
            $add_props = '<c:supported-calendar-component-set /><a:calendar-color />';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"' . $add_ns . '>'
                . '<d:prop>'
                    . '<d:resourcetype />'
                    . '<d:displayname />'
                    // . '<d:sync-token />'
                    . '<cs:getctag />'
                    . $add_props
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request($root_href, 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $folders = [];
        foreach ($response->getElementsByTagName('response') as $element) {
            $folder = $this->getFolderPropertiesFromResponse($element);

            // Note: Addressbooks don't have 'type' specified
            if (($component == 'VCARD' && in_array('addressbook', $folder['resource_type']))
                || $folder['type'] === $component
            ) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    /**
     * Create a DAV object in a folder
     */
    public function create($location, $content, $component = 'VEVENT')
    {
        $ctype = [
            'VEVENT' => 'text/calendar',
            'VTODO' => 'text/calendar',
            'VCARD' => 'text/vcard',
        ];

        $headers = ['Content-Type' => $ctype[$component] . '; charset=utf-8'];

        $response = $this->request($location, 'PUT', $content, $headers);

        if ($response !== false) {
            $etag = $this->responseHeaders['etag'];

            if (preg_match('|^".*"$|', $etag)) {
                $etag = substr($etag, 1, -1);
            }

            return $etag;
        }

        return false;
    }

    /**
     * Update a DAV object in a folder
     */
    public function update($location, $content, $component = 'VEVENT')
    {
        return $this->create($location, $content, $component);
    }

    /**
     * Delete a DAV object from a folder
     */
    public function delete($location)
    {
        $response = $this->request($location, 'DELETE', '', ['Depth' => 1, 'Prefer' => 'return-minimal']);

        return $response !== false;
    }

    /**
     * Fetch DAV objects metadata (ETag, href) a folder
     */
    public function getIndex($location, $component = 'VEVENT')
    {
        $queries = [
            'VEVENT' => 'calendar-query',
            'VTODO' => 'calendar-query',
            'VCARD' => 'addressbook-query',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $filter = '';
        if ($component != 'VCARD') {
            $filter = '<c:comp-filter name="VCALENDAR">'
                    . '<c:comp-filter name="' . $component . '" />'
                . '</c:comp-filter>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .' <c:' . $queries[$component] . ' xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component]. '">'
                . '<d:prop>'
                    . '<d:getetag />'
                . '</d:prop>'
                . ($filter ? "<c:filter>$filter</c:filter>" : '')
            . '</c:' . $queries[$component] . '>';

        $response = $this->request($location, 'REPORT', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->getObjectPropertiesFromResponse($element);
        }

        return $objects;
    }

    /**
     * Fetch DAV objects data from a folder
     */
    public function getData($location, $component = 'VEVENT', $hrefs = [])
    {
        if (empty($hrefs)) {
            return [];
        }

        $body = '';
        foreach ($hrefs as $href) {
            $body .= '<d:href>' . $href . '</d:href>';
        }

        $queries = [
            'VEVENT' => 'calendar-multiget',
            'VTODO' => 'calendar-multiget',
            'VCARD' => 'addressbook-multiget',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $types = [
            'VEVENT' => 'calendar-data',
            'VTODO' => 'calendar-data',
            'VCARD' => 'address-data',
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .' <c:' . $queries[$component] . ' xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component] . '">'
                . '<d:prop>'
                    . '<d:getetag />'
                    . '<c:' . $types[$component]. ' />'
                . '</d:prop>'
                . $body
            . '</c:' . $queries[$component] . '>';

        $response = $this->request($location, 'REPORT', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->getObjectPropertiesFromResponse($element);
        }

        return $objects;
    }

    /**
     * Parse XML content
     */
    protected function parseXML($xml)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');

        if (stripos($xml, '<?xml') === 0) {
            if (!$doc->loadXML($xml)) {
                throw new Exception("Failed to parse XML");
            }

            $doc->formatOutput = true;
        }

        return $doc;
    }

    /**
     * Parse request/response body for debug purposes
     */
    protected function debugBody($body, $headers)
    {
        $head = '';
        foreach ($headers as $header_name => $header_value) {
            $head .= "{$header_name}: {$header_value}\n";
        }

        if (stripos($body, '<?xml') === 0) {
            $doc = new DOMDocument('1.0', 'UTF-8');

            $doc->formatOutput = true;
            $doc->preserveWhiteSpace = false;

            if (!$doc->loadXML($body)) {
                throw new Exception("Failed to parse XML");
            }

            $body = $doc->saveXML();
        }

        return $head . "\n" . rtrim($body);
    }

    /**
     * Extract folder properties from a server 'response' element
     */
    protected function getFolderPropertiesFromResponse(DOMNode $element)
    {

        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $href = $href->nodeValue;
/*
            $path = parse_url($this->url, PHP_URL_PATH);
            if ($path && strpos($href, $path) === 0) {
                $href = substr($href, strlen($path));
            }
*/
        }

        if ($color = $element->getElementsByTagName('calendar-color')->item(0)) {
            if (preg_match('/^#[0-9A-F]{8}$/', $color->nodeValue)) {
                $color = substr($color->nodeValue, 1, -2);
            } else {
                $color = null;
            }
        }

        if ($name = $element->getElementsByTagName('displayname')->item(0)) {
            $name = $name->nodeValue;
        }

        if ($ctag = $element->getElementsByTagName('getctag')->item(0)) {
            $ctag = $ctag->nodeValue;
        }

        $component = null;
        if ($set_element = $element->getElementsByTagName('supported-calendar-component-set')->item(0)) {
            if ($comp_element = $set_element->getElementsByTagName('comp')->item(0)) {
                $component = $comp_element->attributes->getNamedItem('name')->nodeValue;
            }
        }

        $types = [];
        if ($type_element = $element->getElementsByTagName('resourcetype')->item(0)) {
            foreach ($type_element->childNodes as $node) {
                $_type = explode(':', $node->nodeName);
                $types[] = count($_type) > 1 ? $_type[1] : $_type[0];
            }
        }

        return [
            'href' => $href,
            'name' => $name,
            'ctag' => $ctag,
            'color' => $color,
            'type' => $component,
            'resource_type' => $types,
        ];
    }

    /**
     * Extract object properties from a server 'response' element
     */
    protected function getObjectPropertiesFromResponse(DOMNode $element)
    {
        $uid = null;
        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $href = $href->nodeValue;
/*
            $path = parse_url($this->url, PHP_URL_PATH);
            if ($path && strpos($href, $path) === 0) {
                $href = substr($href, strlen($path));
            }
*/
            // Extract UID from the URL
            $href_parts = explode('/', $href);
            $uid = preg_replace('/\.[a-z]+$/', '', $href_parts[count($href_parts)-1]);
        }

        if ($data = $element->getElementsByTagName('calendar-data')->item(0)) {
            $data = $data->nodeValue;
        }
        else if ($data = $element->getElementsByTagName('address-data')->item(0)) {
            $data = $data->nodeValue;
        }

        if ($etag = $element->getElementsByTagName('getetag')->item(0)) {
            $etag = $etag->nodeValue;
            if (preg_match('|^".*"$|', $etag)) {
                $etag = substr($etag, 1, -1);
            }
        }

        return [
            'href' => $href,
            'data' => $data,
            'etag' => $etag,
            'uid' => $uid,
        ];
    }

    /**
     * Initialize HTTP request object
     */
    protected function initRequest($url = '', $method = 'GET', $config = array())
    {
        $rcube       = rcube::get_instance();
        $http_config = (array) $rcube->config->get('kolab_http_request');

        // deprecated configuration options
        if (empty($http_config)) {
            foreach (array('ssl_verify_peer', 'ssl_verify_host') as $option) {
                $value = $rcube->config->get('kolab_' . $option, true);
                if (is_bool($value)) {
                    $http_config[$option] = $value;
                }
            }
        }

        if (!empty($config)) {
            $http_config = array_merge($http_config, $config);
        }

        // load HTTP_Request2 (support both composer-installed and system-installed package)
        if (!class_exists('HTTP_Request2')) {
            require_once 'HTTP/Request2.php';
        }

        try {
            $request = new HTTP_Request2();
            $request->setConfig($http_config);

            // proxy User-Agent string
            $request->setHeader('user-agent', $_SERVER['HTTP_USER_AGENT']);

            // cleanup
            $request->setBody('');
            $request->setUrl($url);
            $request->setMethod($method);

            return $request;
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, true);
        }
    }
}
