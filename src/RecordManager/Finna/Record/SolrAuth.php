<?php
/**
 * Additional functionality for Finna Solr records.
 *
 * PHP version 7
 *
 * Copyright (C) The National Library 2015-2019.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  RecordDrivers
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:record_drivers Wiki
 */
namespace RecordManager\Finna\Record;

/**
 * Additional functionality for Finna Solr records.
 *
 * @category VuFind
 * @package  RecordDrivers
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:record_drivers Wiki
 *
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 */
trait SolrAuth
{
    /**
     * Return authhority id with namespace (if configured in datasources.ini).
     *
     * @param string $id   Authority id
     * @param string $type Authority type
     *
     * @return string
     */
    protected function getAuthorityIdWithNamespace($id, $type = 'author')
    {
        if (strpos($id, ':') !== false) {
            // Assume that id is an URI if it contains a ':'
            return $id;
        }

        if (! ($this->dataSourceSettings[$this->source]['authority'] ?? null)) {
            return $id;
        }

        $settings = $this->dataSourceSettings[$this->source]['authority'];
        $authSrc = $settings[$type] ?? ($settings['*'] ?? null);
        return $authSrc ? "$authSrc.$id" : $id;
    }
}
