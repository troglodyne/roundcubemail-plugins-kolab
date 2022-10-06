<?php

/**
 * CalDAV calendar storage class simulating a virtual calendar listing pedning/declined invitations
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2014-2022, Apheleia IT AG <contact@apheleia-it.ch>
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

require_once(__DIR__ . '/../kolab/kolab_driver.php');
require_once(__DIR__ . '/../kolab/kolab_invitation_calendar.php');

class caldav_invitation_calendar extends kolab_invitation_calendar
{
    public $id = '__caldav_invitation__';

    /**
     * Default constructor
     */
    public function __construct($id, $calendar)
    {
        $this->cal = $calendar;
        $this->id  = $id;
    }

    /**
     * Compose an URL for CalDAV access to this calendar (if configured)
     */
    public function get_caldav_url()
    {
        return false; // TODO
    }
}
