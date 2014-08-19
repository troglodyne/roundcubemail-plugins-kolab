<?php

/**
 * Kolab Configuration data model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_configuration extends kolab_format
{
    public $CTYPE   = 'application/x-vnd.kolab.configuration';
    public $CTYPEv2 = 'application/x-vnd.kolab.configuration';

    protected $objclass   = 'Configuration';
    protected $read_func  = 'readConfiguration';
    protected $write_func = 'writeConfiguration';

    private $type_map = array(
        'category'   => Configuration::TypeCategoryColor,
        'dictionary' => Configuration::TypeDictionary,
        'relation'   => Configuration::TypeRelation,
        'snippet'    => Configuration::TypeSnippet,
    );


    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    public function set(&$object)
    {
        // set common object properties
        parent::set($object);

        // read type-specific properties
        switch ($object['type']) {
        case 'dictionary':
            $dict = new Dictionary($object['language']);
            $dict->setEntries(self::array2vector($object['e']));
            $this->obj = new Configuration($dict);
            break;

        case 'category':
            // TODO: implement this
            $categories = new vectorcategorycolor;
            $this->obj = new Configuration($categories);
            break;

        case 'relation':
            $relation = new Relation(strval($object['name']), strval($object['category']));

            if ($object['color']) {
                $relation->setColor($object['color']);
            }
            if ($object['parent']) {
                $relation->setParent($object['parent']);
            }
            if ($object['iconName']) {
                $relation->setIconName($object['iconName']);
            }
            if ($object['priority'] > 0) {
                $relation->setPriority((int) $object['priority']);
            }
            if (!empty($object['members'])) {
                $relation->setMembers(self::array2vector($object['members']));
            }

            $this->obj = new Configuration($relation);
            break;

        case 'snippet':
            $collection = new SnippetCollection($object['name']);
            $snippets   = new vectorsnippets;

            foreach ((array) $object['snippets'] as $item) {
                $snippet = new snippet($item['name'], $item['text']);
                $snippet->setTextType(strtolower($item['type']) == 'html' ? Snippet::HTML : Snippet::Plain);
                if ($item['shortcut']) {
                    $snippet->setShortCut($item['shortcut']);
                }

                $snippets->push($snippet);
            }

            $collection->setSnippets($snippets);

            $this->obj = new Configuration($collection);
            break;

        default:
            return false;
        }

        // adjust content-type string
        $this->CTYPE = $this->CTYPEv2 = 'application/x-vnd.kolab.configuration.' . $object['type'];

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data || (is_object($this->obj) && $this->obj->isValid());
    }

    /**
     * Convert the Configuration object into a hash array data structure
     *
     * @param array Additional data for merge
     *
     * @return array  Config object data as hash array
     */
    public function to_array($data = array())
    {
        // return cached result
        if (!empty($this->data)) {
            return $this->data;
        }

        // read common object props into local data object
        $object = parent::to_array($data);

        $type_map = array_flip($this->type_map);

        $object['type'] = $type_map[$this->obj->type()];

        // read type-specific properties
        switch ($object['type']) {
        case 'dictionary':
            $dict = $this->obj->dictionary();
            $object['language'] = $dict->language();
            $object['e'] = self::vector2array($dict->entries());
            break;

        case 'category':
            // TODO: implement this
            break;

        case 'relation':
            $relation = $this->obj->relation();

            $object['name']     = $relation->name();
            $object['category'] = $relation->type();
            $object['color']    = $relation->color();
            $object['parent']   = $relation->parent();
            $object['iconName'] = $relation->iconName();
            $object['priority'] = $relation->priority();
            $object['members']  = self::vector2array($relation->members());

            break;

        case 'snippet':
            $collection = $this->obj->snippets();

            $object['name']     = $collection->name();
            $object['snippets'] = array();

            $snippets = $collection->snippets();
            for ($i=0; $i < $snippets->size(); $i++) {
                $snippet = $snippets->get($i);
                $object['snippets'][] = array(
                    'name'     => $snippet->name(),
                    'text'     => $snippet->text(),
                    'type'     => $snippet->textType() == Snippet::HTML ? 'html' : 'plain',
                    'shortcut' => $snippet->shortCut(),
                );
            }

            break;
        }

        // adjust content-type string
        if ($object['type']) {
            $this->CTYPE = $this->CTYPEv2 = 'application/x-vnd.kolab.configuration.' . $object['type'];
        }

        $this->data = $object;
        return $this->data;
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        switch ($this->data['type']) {
        case 'dictionary':
            $tags = array($this->data['language']);
            break;

        case 'relation':
            $tags = array('category:' . $this->data['category']);
            break;
        }

        return $tags;
    }
}
