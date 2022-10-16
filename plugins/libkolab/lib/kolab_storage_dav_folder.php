<?php

/**
 * A class representing a DAV folder object.
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
class kolab_storage_dav_folder extends kolab_storage_folder
{
    public $dav;
    public $href;
    public $attributes;

    /**
     * Object constructor
     */
    public function __construct($dav, $attributes, $type_annotation = '')
    {
        $this->attributes = $attributes;

        $this->href  = $this->attributes['href'];
        $this->id    = md5($this->href);
        $this->dav   = $dav;
        $this->valid = true;

        list($this->type, $suffix) = explode('.', $type_annotation);
        $this->default = $suffix == 'default';
        $this->subtype = $this->default ? '' : $suffix;

        // Init cache
        $this->cache = kolab_storage_dav_cache::factory($this);
    }

    /**
     * Returns the owner of the folder.
     *
     * @param bool Return a fully qualified owner name (i.e. including domain for shared folders)
     *
     * @return string The owner of this folder.
     */
    public function get_owner($fully_qualified = false)
    {
        // return cached value
        if (isset($this->owner)) {
            return $this->owner;
        }

        $rcube = rcube::get_instance();
        $this->owner = $rcube->get_user_name();
        $this->valid = true;

        // TODO: Support shared folders

        return $this->owner;
    }

    /**
     * Get a folder Etag identifier
     */
    public function get_ctag()
    {
        return $this->attributes['ctag'];
    }

    /**
     * Getter for the name of the namespace to which the folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        // TODO: Support shared folders
        return 'personal';
    }

    /**
     * Get the display name value of this folder
     *
     * @return string Folder name
     */
    public function get_name()
    {
        return kolab_storage_dav::object_name($this->attributes['name']);
    }

    /**
     * Getter for the top-end folder name (not the entire path)
     *
     * @return string Name of this folder
     */
    public function get_foldername()
    {
        return $this->attributes['name'];
    }

    public function get_folder_info()
    {
        return []; // todo ?
    }

    /**
     * Getter for parent folder path
     *
     * @return string Full path to parent folder
     */
    public function get_parent()
    {
        // TODO
        return '';
    }

    /**
     * Compose a unique resource URI for this folder
     */
    public function get_resource_uri()
    {
        if (!empty($this->resource_uri)) {
            return $this->resource_uri;
        }

        // compose fully qualified resource uri for this instance
        $host = preg_replace('|^https?://|', 'dav://' . urlencode($this->get_owner(true)) . '@', $this->dav->url);
        $path = $this->href[0] == '/' ? $this->href : "/{$this->href}";

        $host_path = parse_url($host, PHP_URL_PATH);
        if ($host_path && strpos($path, $host_path) === 0) {
            $path = substr($path, strlen($host_path));
        }

        $this->resource_uri = unslashify($host) . $path;

        return $this->resource_uri;
    }

    /**
     * Getter for the Cyrus mailbox identifier corresponding to this folder
     * (e.g. user/john.doe/Calendar/Personal@example.org)
     *
     * @return string Mailbox ID
     */
    public function get_mailbox_id()
    {
        // TODO: This is used with Bonnie related features
        return '';
    }

    /**
     * Get the color value stored in metadata
     *
     * @param string Default color value to return if not set
     *
     * @return mixed Color value from the folder metadata or $default if not set
     */
    public function get_color($default = null)
    {
        return !empty($this->attributes['color']) ? $this->attributes['color'] : $default;
    }

    /**
     * Get ACL information for this folder
     *
     * @return string Permissions as string
     */
    public function get_myrights()
    {
        // TODO
        return '';
    }

    /**
     * Helper method to extract folder UID
     *
     * @return string Folder's UID
     */
    public function get_uid()
    {
        // TODO ???
        return '';
    }

    /**
     * Check activation status of this folder
     *
     * @return bool True if enabled, false if not
     */
    public function is_active()
    {
        // TODO
        return true;
    }

    /**
     * Change activation status of this folder
     *
     * @param bool The desired subscription status: true = active, false = not active
     *
     * @return bool True on success, false on error
     */
    public function activate($active)
    {
        // TODO
        return true;
    }

    /**
     * Check subscription status of this folder
     *
     * @return bool True if subscribed, false if not
     */
    public function is_subscribed()
    {
        // TODO
        return true;
    }

    /**
     * Change subscription status of this folder
     *
     * @param bool The desired subscription status: true = subscribed, false = not subscribed
     *
     * @return True on success, false on error
     */
    public function subscribe($subscribed)
    {
        // TODO
        return true;
    }

    /**
     * Delete the specified object from this folder.
     *
     * @param array|string $object  The Kolab object to delete or object UID
     * @param bool         $expunge Should the folder be expunged?
     *
     * @return bool True if successful, false on error
     */
    public function delete($object, $expunge = true)
    {
        if (!$this->valid) {
            return false;
        }

        $uid = is_array($object) ? $object['uid'] : $object;

        $success = $this->dav->delete($this->object_location($uid), $content);

        if ($success) {
            $this->cache->set($uid, false);
        }

        return $success;
    }

    /**
     *
     */
    public function delete_all()
    {
        if (!$this->valid) {
            return false;
        }

        // TODO: This method is used by kolab_addressbook plugin only
        // $this->cache->purge();

        return false;
    }

    /**
     * Restore a previously deleted object
     *
     * @param string $uid Object UID
     *
     * @return mixed Message UID on success, false on error
     */
    public function undelete($uid)
    {
        if (!$this->valid) {
            return false;
        }

        // TODO

        return false;
    }

    /**
     * Move a Kolab object message to another IMAP folder
     *
     * @param string Object UID
     * @param string IMAP folder to move object to
     *
     * @return bool True on success, false on failure
     */
    public function move($uid, $target_folder)
    {
        if (!$this->valid) {
            return false;
        }

        // TODO

        return false;
    }

    /**
     * Save an object in this folder.
     *
     * @param array  $object The array that holds the data of the object.
     * @param string $type   The type of the kolab object.
     * @param string $uid    The UID of the old object if it existed before
     *
     * @return mixed False on error or object UID on success
     */
    public function save(&$object, $type = null, $uid = null)
    {
        if (!$this->valid || empty($object)) {
            return false;
        }

        if (!$type) {
            $type = $this->type;
        }
/*
        // copy attachments from old message
        $copyfrom = $object['_copyfrom'] ?: $object['_msguid'];
        if (!empty($copyfrom) && ($old = $this->cache->get($copyfrom, $type, $object['_mailbox']))) {
            foreach ((array)$old['_attachments'] as $key => $att) {
                if (!isset($object['_attachments'][$key])) {
                    $object['_attachments'][$key] = $old['_attachments'][$key];
                }
                // unset deleted attachment entries
                if ($object['_attachments'][$key] == false) {
                    unset($object['_attachments'][$key]);
                }
                // load photo.attachment from old Kolab2 format to be directly embedded in xcard block
                else if ($type == 'contact' && ($key == 'photo.attachment' || $key == 'kolab-picture.png') && $att['id']) {
                    if (!isset($object['photo']))
                        $object['photo'] = $this->get_attachment($copyfrom, $att['id'], $object['_mailbox']);
                    unset($object['_attachments'][$key]);
                }
            }
        }

        // process attachments
        if (is_array($object['_attachments'])) {
            $numatt = count($object['_attachments']);
            foreach ($object['_attachments'] as $key => $attachment) {
                // FIXME: kolab_storage and Roundcube attachment hooks use different fields!
                if (empty($attachment['content']) && !empty($attachment['data'])) {
                    $attachment['content'] = $attachment['data'];
                    unset($attachment['data'], $object['_attachments'][$key]['data']);
                }

                // make sure size is set, so object saved in cache contains this info
                if (!isset($attachment['size'])) {
                    if (!empty($attachment['content'])) {
                        if (is_resource($attachment['content'])) {
                            // this need to be a seekable resource, otherwise
                            // fstat() failes and we're unable to determine size
                            // here nor in rcube_imap_generic before IMAP APPEND
                            $stat = fstat($attachment['content']);
                            $attachment['size'] = $stat ? $stat['size'] : 0;
                        }
                        else {
                            $attachment['size'] = strlen($attachment['content']);
                        }
                    }
                    else if (!empty($attachment['path'])) {
                        $attachment['size'] = filesize($attachment['path']);
                    }
                    $object['_attachments'][$key] = $attachment;
                }

                // generate unique keys (used as content-id) for attachments
                if (is_numeric($key) && $key < $numatt) {
                    // derrive content-id from attachment file name
                    $ext = preg_match('/(\.[a-z0-9]{1,6})$/i', $attachment['name'], $m) ? $m[1] : null;
                    $basename = preg_replace('/[^a-z0-9_.-]/i', '', basename($attachment['name'], $ext));  // to 7bit ascii
                    if (!$basename) $basename = 'noname';
                    $cid = $basename . '.' . microtime(true) . $key . $ext;

                    $object['_attachments'][$cid] = $attachment;
                    unset($object['_attachments'][$key]);
                }
            }
        }
*/
        $rcmail = rcube::get_instance();
        $result = false;

        // generate and save object message
        if ($content = $this->to_dav($object)) {
            $method   = $uid ? 'update' : 'create';
            $dav_type = $this->get_dav_type();
            $result   = $this->dav->{$method}($this->object_location($object['uid']), $content, $dav_type);

            // Note: $result can be NULL if the request was successful, but ETag wasn't returned
            if ($result !== false) {
                // insert/update object in the cache
                $object['etag'] = $result;
                $this->cache->save($object, $uid);
                $result = true;
            }
        }

        return $result;
    }

    /**
     * Fetch the object the DAV server and convert to internal format
     *
     * @param string The object UID to fetch
     * @param string The object type expected (use wildcard '*' to accept all types)
     * @param string Unused (kept for compat. with the parent class)
     *
     * @return mixed Hash array representing the Kolab object, a kolab_format instance or false if not found
     */
    public function read_object($uid, $type = null, $folder = null)
    {
        if (!$this->valid) {
            return false;
        }

        $href    = $this->object_location($uid);
        $objects = $this->dav->getData($this->href, $this->get_dav_type(), [$href]);

        if (!is_array($objects) || count($objects) != 1) {
            rcube::raise_error([
                    'code' => 900,
                    'message' => "Failed to fetch {$href}"
                ], true);
            return false;
        }

        return $this->from_dav($objects[0]);
    }

    /**
     * Convert DAV object into PHP array
     *
     * @param array Object data in kolab_dav_client::fetchData() format
     *
     * @return array Object properties
     */
    public function from_dav($object)
    {
        if ($this->type == 'event') {
            $ical = libcalendaring::get_ical();
            $events = $ical->import($object['data']);

            if (!count($events) || empty($events[0]['uid'])) {
                return false;
            }

            $result = $events[0];
        }
        else if ($this->type == 'contact') {
            if (stripos($object['data'], 'BEGIN:VCARD') !== 0) {
                return false;
            }

            $vcard = new rcube_vcard($object['data'], RCUBE_CHARSET, false);

            if (!empty($vcard->displayname) || !empty($vcard->surname) || !empty($vcard->firstname) || !empty($vcard->email)) {
                $result = $vcard->get_assoc();
            }
            else {
                return false;
            }
        }

        $result['etag'] = $object['etag'];
        $result['href'] = $object['href'];
        $result['uid']  = $object['uid'] ?: $result['uid'];

        return $result;
    }

    /**
     * Convert Kolab object into DAV format (iCalendar)
     */
    public function to_dav($object)
    {
        $result = '';

        if ($this->type == 'event') {
            $ical = libcalendaring::get_ical();
            if (!empty($object['exceptions'])) {
                $object['recurrence']['EXCEPTIONS'] = $object['exceptions'];
            }

            $result = $ical->export([$object]);
        }
        else if ($this->type == 'contact') {
            // copy values into vcard object
            $vcard = new rcube_vcard('', RCUBE_CHARSET, false, ['uid' => 'UID']);

            $vcard->set('groups', null);

            foreach ($object as $key => $values) {
                list($field, $section) = rcube_utils::explode(':', $key);

                // avoid casting DateTime objects to array
                if (is_object($values) && is_a($values, 'DateTime')) {
                    $values = [$values];
                }

                foreach ((array) $values as $value) {
                    if (isset($value)) {
                        $vcard->set($field, $value, $section);
                    }
                }
            }

            $result = $vcard->export(false);
        }

        if ($result) {
            // The content must be UTF-8, otherwise if we try to fetch the object
            // from server XML parsing would fail.
            $result = rcube_charset::clean($result);
        }

        return $result;
    }

    protected function object_location($uid)
    {
        return unslashify($this->href) . '/' . urlencode($uid) . '.' . $this->get_dav_ext();
    }

    /**
     * Get a folder DAV content type
     */
    public function get_dav_type()
    {
        $types = [
            'event' => 'VEVENT',
            'task'  => 'VTODO',
            'contact' => 'VCARD',
        ];

        return $types[$this->type];
    }


    /**
     * Get a DAV file extension for specified Kolab type
     */
    public function get_dav_ext()
    {
        $types = [
            'event' => 'ics',
            'task'  => 'ics',
            'contact' => 'vcf',
        ];

        return $types[$this->type];
    }

    /**
     * Return folder name as string representation of this object
     *
     * @return string Full IMAP folder name
     */
    public function __toString()
    {
        return $this->attributes['name'];
    }
}
