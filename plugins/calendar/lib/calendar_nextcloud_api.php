<?php

/**
 * Nextcloud API for the Calendar plugin.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) Apheleia IT AG <contact@apheleia-it.ch>
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
class calendar_nextcloud_api
{

    /**
     * Make a request to the Nextcloud API
     *
     * @return false|array Response data or False on failure
     */
    protected function request($path, $method = 'GET', $params = [])
    {
        $rcmail = rcube::get_instance();

        $url = unslashify($rcmail->config->get('calendar_nextcloud_url'));
        $url .= "/ocs/v2.php/$path";

        try {
            $request_config = [
                'store_body'       => true,
                'follow_redirects' => true,
            ];

            $request = libkolab::http_request($url, $method, $request_config);

            // Authentication
            $request->setAuth(
                $rcmail->user->get_username(),
                $rcmail->decrypt($_SESSION['password'])
            );

            // Disable CSRF prevention, and enable JSON responses
            $request->setHeader([
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
            ]);

            if (!empty($params)) {
                $request->addPostParameter($params);
            }

            // Send the request
            $response = $request->send();

            $body = $response->getBody();
            $code = $response->getStatus();

            if ($code < 400) {
                return json_decode($body, true);
            }

            if (strpos($body, '<?xml') === 0) {
                $doc = new DOMDocument();
                $doc->loadXML($body);
                $code = $doc->getElementsByTagName('statuscode')->item(0)->textContent;
                $msg = $doc->getElementsByTagName('message')->item(0)->textContent;
            }
            else {
                $msg = 'Unknown error';
            }

            throw new Exception("Nextcloud API Error: [$code] $msg");
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }

        return false;
    }

    /**
     * Create a Talk room
     *
     * @return string|false Room URL
     */
    public function talk_room_create($name = '')
    {
        $rcmail = rcube::get_instance();

        $params = [
            'roomType' => 3,
            'roomName' => $name ?: $rcmail->gettext('calendar.talkroomname'),
        ];

        $response = $this->request('apps/spreed/api/v4/room', 'POST', $params);

        if (is_array($response) && !empty($response['ocs']['data']['token'])) {
            $url = unslashify($rcmail->config->get('calendar_nextcloud_url'));
            return $url . '/call/' . $response['ocs']['data']['token'];
        }

        return false;
    }
}
