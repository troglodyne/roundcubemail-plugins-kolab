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

    protected $rc;
    protected $responseHeaders = [];

    /**
     * Object constructor
     */
    public function __construct($url)
    {
        $this->url = $url;
        $this->rc = rcube::get_instance();
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

            $request->setAuth($this->rc->user->get_username(), $this->rc->decrypt($_SESSION['password']));

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
/*
        $path = parse_url($this->url, PHP_URL_PATH);

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:">'
                . '<d:prop>'
                    . '<d:current-user-principal />'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request('/calendars', 'PROPFIND', $body);

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

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
                . '<d:prop>'
                    . '<c:calendar-home-set />'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($principal_href, 'PROPFIND', $body);
*/
        $roots = [
            'VEVENT' => 'calendars',
            'VTODO' => 'calendars',
            'VCARD' => 'addressbooks',
        ];

        $principal_href = '/' . $roots[$component] . '/' . $this->rc->user->get_username();

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">'
                . '<d:prop>'
                    . '<d:resourcetype />'
                    . '<d:displayname />'
                    . '<cs:getctag />'
                    . '<c:supported-calendar-component-set />'
                    . '<a:calendar-color />'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($principal_href, 'PROPFIND', $body);

        if (empty($response)) {
            return false;
        }

        $folders = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $folder = $this->getFolderPropertiesFromResponse($element);
            if ($folder['type'] === $component) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    /**
     * Create DAV object in a folder
     */
    public function create($location, $content)
    {
        $response = $this->request($location, 'PUT', $content, ['Content-Type' => 'text/calendar; charset=utf-8']);

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
     * Delete DAV object from a folder
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
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .' <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
                . '<d:prop>'
                    . '<d:getetag />'
                . '</d:prop>'
                . '<c:filter>'
                    . '<c:comp-filter name="VCALENDAR">'
                        . '<c:comp-filter name="' . $component . '" />'
                    . '</c:comp-filter>'
                . '</c:filter>'
            . '</c:calendar-query>';

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
    public function getData($location, $hrefs = [])
    {
        if (empty($hrefs)) {
            return [];
        }

        $body = '';
        foreach ($hrefs as $href) {
            $body .= '<d:href>' . $href . '</d:href>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .' <c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
                . '<d:prop>'
                    . '<d:getetag />'
                    . '<c:calendar-data />'
                . '</d:prop>'
                . $body
            . '</c:calendar-multiget>';

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

            if (!$doc->loadXML($body)) {
                throw new Exception("Failed to parse XML");
            }

            $doc->formatOutput = true;

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

        return [
            'href' => $href,
            'name' => $name,
            'ctag' => $ctag,
            'color' => $color,
            'type' => $component,
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

        // load HTTP_Request2
        require_once 'HTTP/Request2.php';

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
