<?php
/**
 * OAI-PMH Harvesting Class
 *
 * Based on harvest-oai.php in VuFind
 *
 * PHP version 5
 *
 * Copyright (c) Demian Katz 2010.
 * Copyright (c) The National Library of Finland 2011-2019.
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
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Harvest;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Utils\Logger;

/**
 * OaiPmh Class
 *
 * This class harvests records via OAI-PMH using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class OaiPmh extends Base
{
    /**
     * Set to harvest (null for all records)
     *
     * @var string
     */
    protected $set = null;

    /**
     * Metadata type to harvest
     *
     * @var string
     */
    protected $metadata = 'oai_dc';

    /**
     * OAI prefix to strip from ID values
     *
     * @var string
     */
    protected $idPrefix = '';

    /**
     * Regular expression searches for ID substitutions
     *
     * @var array
     */
    protected $idSearch = [];

    /**
     * Replacements for regular expression matches for ID substitutions
     *
     * @var array
     */
    protected $idReplace = [];

    /**
     * Date granularity
     *
     * @var string
     */
    protected $granularity = 'auto';

    /**
     * File where to dump OAI requests and responses for debugging
     *
     * @var string
     */
    protected $debugLog = '';

    /**
     * Resumption token to use to override the first harvest request
     *
     * @var string
     */
    protected $resumptionToken = '';

    /**
     * Date received from server via Identify command. Used to set the last
     * harvest date.
     *
     * @var string
     */
    protected $serverDate = null;

    /**
     * Whether to ignore noRecordsMatch error when harvesting
     * (broken sources may report an error even with a valid resumptionToken)
     *
     * @var bool
     */
    protected $ignoreNoRecordsMatch = false;

    /**
     * Current response being processed
     *
     * @var DOMDocument
     */
    protected $xml = null;

    protected $sameResumptionTokenLimit = 100;

    /**
     * Constructor.
     *
     * @param Database $db       Database
     * @param Logger   $logger   The Logger object used for logging messages
     * @param string   $source   The data source to be harvested
     * @param string   $basePath RecordManager main directory location
     * @param array    $config   Main configuration
     * @param array    $settings Settings from datasources.ini
     *
     * @throws Exception
     */
    public function __construct(Database $db, Logger $logger, $source, $basePath,
        $config, $settings
    ) {
        parent::__construct($db, $logger, $source, $basePath, $config, $settings);

        if (isset($settings['set'])) {
            $this->set = $settings['set'];
        }
        if (isset($settings['metadataPrefix'])) {
            $this->metadata = $settings['metadataPrefix'];
        }
        if (isset($settings['idPrefix'])) {
            $this->idPrefix = $settings['idPrefix'];
        }
        if (isset($settings['idSearch'])) {
            $this->idSearch = $settings['idSearch'];
        }
        if (isset($settings['idReplace'])) {
            $this->idReplace = $settings['idReplace'];
        }
        if (isset($settings['dateGranularity'])) {
            $this->granularity = $settings['dateGranularity'];
        }
        if (isset($settings['debuglog'])) {
            $this->debugLog = $settings['debuglog'];
        }
        if (isset($settings['oaipmhTransformation'])) {
            $style = new \DOMDocument();
            $loadResult = $style->load(
                "$basePath/transformations/" . $settings['oaipmhTransformation']
            );
            if (false === $loadResult) {
                throw new \Exception(
                    "Could not load $basePath/transformations/"
                    . $settings['oaipmhTransformation']
                );
            }
            $this->preXslt = new \XSLTProcessor();
            $this->preXslt->importStylesheet($style);
        }
        if (isset($settings['ignoreNoRecordsMatch'])) {
            $this->ignoreNoRecordsMatch = $settings['ignoreNoRecordsMatch'];
        }
        if (isset($settings['sameResumptionTokenLimit'])) {
            $this->sameResumptionTokenLimit
                = $settings['sameResumptionTokenLimit'];
        }

        $this->identifyServer();
    }

    /**
     * Override the resumption token.
     *
     * @param string $token New resumption token
     *
     * @return void
     */
    public function setResumptionToken($token)
    {
        $this->resumptionToken = $token;
    }

    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setStartDate($date)
    {
        $this->startDate = $date;
    }

    /**
     * Set an end date for the harvest (only harvest records BEFORE this date).
     *
     * @param string $date End date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setEndDate($date)
    {
        $this->endDate = $date;
    }

    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     */
    public function harvest($callback)
    {
        $this->initHarvest($callback);

        if ($this->resumptionToken) {
            $this->message('Incremental harvest from given resumptionToken');
            $token = $this->getRecordsByToken($this->resumptionToken);
        } else {
            // Start harvesting at the requested date:
            if (!empty($this->startDate)) {
                $this->message(
                    'Incremental harvest from timestamp ' . $this->startDate
                );
            } else {
                $this->message('Initial harvest for all records');
            }
            $token = $this->getRecordsByDate();
        }

        // Keep harvesting as long as a resumption token is provided:
        $this->resetSafeguard();
        while ($token !== false) {
            $this->reportResults();
            $token = $this->getRecordsByToken($token);
            $this->safeguard($token);
        }
        $this->reportResults();
    }

    /**
     * List identifiers of all available documents.
     *
     * @param callable $callback Function to be called to process an identifier
     *
     * @return void
     */
    public function listIdentifiers($callback)
    {
        $this->initHarvest($callback);

        if ($this->resumptionToken) {
            $this->message('Incremental listing from given resumptionToken');
            $token = $this->getIdentifiersByToken($this->resumptionToken);
        } else {
            $this->message('Listing all identifiers');
            $token = $this->getIdentifiers();
        }

        // Keep harvesting as long as a resumption token is provided:
        $this->resetSafeguard();
        while ($token !== false) {
            $this->reportListIdentifiersResults();
            $token = $this->getIdentifiersByToken($token);
            $this->safeguard($token);
        }
        $this->reportListIdentifiersResults();
    }

    /**
     * Reset safeguard
     *
     * @return void
     */
    protected function resetSafeguard()
    {
        $this->lastResumptionToken = '';
        $this->sameResumptionTokenCount = 0;
    }

    /**
     * Safeguard against broken repositories that don't return records and
     * return the same resumption token over and over again
     *
     * @param string $resumptionToken Latest resumption token
     *
     * @return void
     */
    protected function safeguard($resumptionToken)
    {
        if ($this->lastResumptionToken === $resumptionToken) {
            if (++$this->sameResumptionTokenCount >= $this->sameResumptionTokenLimit
            ) {
                throw new \Exception(
                    "{$this->source}: same resumptionToken received"
                    . " {$this->sameResumptionTokenCount} times, aborting"
                );
            }
        } else {
            $this->sameResumptionTokenCount = 0;
            $this->lastResumptionToken = $resumptionToken;
        }
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->message(
            'Harvested ' . $this->changedRecords . ' normal records and '
            . $this->deletedRecords . ' deleted records from ' . $this->source
        );
    }

    /**
     * Display listing progress
     *
     * @return void
     */
    protected function reportListIdentifiersResults()
    {
        $this->message(
            'Listed ' . $this->changedRecords . ' normal records and '
            . $this->deletedRecords . ' deleted records from ' . $this->source
        );
    }

    /**
     * Normalize a date to a Unix timestamp.
     *
     * @param string $date Date (ISO-8601 or YYYY-MM-DD HH:MM:SS)
     *
     * @return integer     Unix timestamp (or false if $date invalid)
     */
    protected function normalizeDate($date)
    {
        // Remove timezone markers -- we don't want PHP to outsmart us by adjusting
        // the time zone!
        $date = str_replace(['T', 'Z'], [' ', ''], $date);

        // Translate to a timestamp:
        return strtotime($date);
    }

    /**
     * Make an OAI-PMH request.  Throw an exception if there is an error;
     * return a SimpleXML object on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return DOMDocument Response as DOM
     * @throws Exception
     * @throws HTTP_Request2_LogicException
     */
    protected function sendRequest($verb, $params = [])
    {
        // Set up the request:
        $request = new \HTTP_Request2(
            $this->baseURL,
            \HTTP_Request2::METHOD_GET,
            $this->httpParams
        );
        $request->setHeader('User-Agent', 'RecordManager');

        // Load request parameters:
        $url = $request->getURL();
        $params['verb'] = $verb;
        $url->setQueryVariables($params);

        $urlStr = $url->getURL();
        if ($this->debugLog) {
            file_put_contents(
                $this->debugLog,
                date('Y-m-d H:i:s') . ' [' . getmypid() . "] Request:\n$urlStr\n",
                FILE_APPEND
            );
        }

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= $this->maxTries; $try++) {
            $this->message("Sending request: $urlStr", true);
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->message(
                            "Request '$urlStr' failed ($code), retrying in "
                            . "{$this->retryWait} seconds...",
                            false,
                            Logger::WARNING
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->message(
                        "Request '$urlStr' failed: $code", false, Logger::FATAL
                    );
                    throw new \Exception("{$this->source}: Request failed: $code");
                }

                $responseStr = $response->getBody();
                if ($this->debugLog) {
                    file_put_contents(
                        $this->debugLog,
                        date('Y-m-d H:i:s') . ' [' . getmypid() . "] Response:\n" .
                        "$responseStr\n\n",
                        FILE_APPEND
                    );
                }
                return $this->processResponse(
                    $responseStr, isset($params['resumptionToken'])
                );
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->message(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw new \Exception("{$this->source}: " . $e->getMessage());
            }
        }
        throw new \Exception("{$this->source}: Request failed");
    }

    /**
     * Load XML into a DOM
     *
     * @param string $xml XML string
     *
     * @return DOMDocument
     */
    protected function loadXML($xml)
    {
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml, LIBXML_PARSEHUGE)) {
            return false;
        }
        if (null !== $this->preXslt) {
            return $this->preXslt->transformToDoc($doc);
        }
        return $doc;
    }

    /**
     * Process an OAI-PMH response into a SimpleXML object.
     * Throw exception if an error is detected.
     *
     * @param string $xml        OAI-PMH response XML
     * @param bool   $resumption Whether this is a request made with a
     *                           resumptionToken
     *
     * @return DOMDocument Response as DOM
     * @throws Exception
     */
    protected function processResponse($xml, $resumption)
    {
        // Parse the XML:
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $result = $this->loadXML($xml);
        if ($result === false || libxml_get_last_error() !== false) {
            // Assuming it's a character encoding issue, this might help...
            $this->message(
                'Invalid XML received, trying to fix the encoding',
                false,
                Logger::WARNING
            );
            $fixedXml = mb_convert_encoding($xml, 'UTF-8', 'UTF-8');
            libxml_clear_errors();
            $result = $this->loadXML($fixedXml);
        }
        if ($result === false || libxml_get_last_error() !== false) {
            $errors = '';
            foreach (libxml_get_errors() as $error) {
                if ($errors) {
                    $errors .= '; ';
                }
                $errors .= 'Error ' . $error->code . ' at '
                    . $error->line . ':' . $error->column . ': '
                    . str_replace(["\n", "\r"], '', $error->message);
            }
            libxml_use_internal_errors($saveUseErrors);
            $tempfile = $this->getTempFileName('oai-pmh-error-', '.xml');
            file_put_contents($tempfile, $xml);
            $this->message(
                "Could not parse XML response: $errors. XML stored in $tempfile",
                false,
                Logger::ERROR
            );
            throw new \Exception("{$this->source}: Failed to parse XML response");
        }
        libxml_use_internal_errors($saveUseErrors);

        // Detect errors and throw an exception if one is found:
        $error = $this->getSingleNode($result, 'error');
        if ($error) {
            $code = $error->getAttribute('code');
            if (($resumption && !$this->ignoreNoRecordsMatch)
                || $code != 'noRecordsMatch'
            ) {
                $value = $result->saveXML($error);
                $this->message(
                    "OAI-PMH server returned error $code ($value)",
                    false,
                    Logger::ERROR
                );
                throw new \Exception(
                    "{$this->source}: OAI-PMH error -- code: $code, " .
                    "value: $value\n"
                );
            }
        }

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Extract the ID from a record object (support method for processRecords()).
     *
     * @param DOMNode $header XML record header
     *
     * @return string The ID value
     */
    protected function extractID($header)
    {
        // Normalize to string:
        $id = $this->getSingleNode($header, 'identifier')->nodeValue;

        // Strip prefix if found:
        if (substr($id, 0, strlen($this->idPrefix)) == $this->idPrefix) {
            $id = substr($id, strlen($this->idPrefix));
        }

        // Apply regular expression matching:
        if (!empty($this->idSearch)) {
            $id = preg_replace($this->idSearch, $this->idReplace, $id);
        }

        // Return final value:
        return $id;
    }

    /**
     * Process the records.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     */
    protected function processRecords($records)
    {
        $count = count($records);
        $this->message("Processing $count records", true);

        // Loop through the records:
        foreach ($records as $record) {
            $header = $this->getSingleNode($record, 'header');

            // Bypass the record if the record is missing its header:
            if ($header === false) {
                $this->message("Record header missing", false, Logger::ERROR);
                echo $this->xml->saveXML($record) . "\n";
                continue;
            }

            // Get the ID of the current record:
            $id = $this->extractID($header);

            // Save the current record, either as a deleted or as a regular record:
            $status = strtolower((string)$header->getAttribute('status'));
            if ($status === 'deleted') {
                call_user_func($this->callback, $this->source, $id, true, null);
                $this->deletedRecords++;
            } else {
                $recordMetadata = $this->getSingleNode($record, 'metadata');
                if ($recordMetadata === false) {
                    $this->message(
                        "No metadata tag found for record $id", false, Logger::ERROR
                    );
                    continue;
                }
                $recordNode = $this->getSingleNode($recordMetadata, '*');
                if ($recordNode === false) {
                    $this->message(
                        "No metadata fields found for record $id",
                        false,
                        Logger::ERROR
                    );
                    continue;
                }
                // Add namespaces to the record element
                $xpath = new \DOMXPath($this->xml);
                foreach ($xpath->query('namespace::*', $recordNode) as $node) {
                    // Bypass default xml namespace
                    if ($node->nodeValue == 'http://www.w3.org/XML/1998/namespace') {
                        continue;
                    }
                    // Check whether the attribute already exists
                    if ($recordNode->getAttributeNode($node->nodeName) !== false) {
                        continue;
                    }
                    $attr = $this->xml->createAttribute($node->nodeName);
                    $attr->value = $node->nodeValue;
                    $recordNode->appendChild($attr);
                }
                $this->changedRecords += call_user_func(
                    $this->callback,
                    $this->source,
                    $id,
                    false,
                    trim($this->xml->saveXML($recordNode))
                );
            }
        }
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecords($params)
    {
        // Make the OAI-PMH request:
        $this->xml = $this->sendRequest('ListRecords', $params);

        // Save the records from the response:
        $listRecords = $this->getSingleNode($this->xml, 'ListRecords');
        if ($listRecords !== false) {
            $records = $this->getImmediateChildrenByTagName($listRecords, 'record');
            if ($records) {
                $this->processRecords($records);
            }

            // If we have a resumption token, keep going; otherwise, we're
            // done -- save the end date.
            $token = $this->getSingleNode($listRecords, 'resumptionToken');
            if ($token !== false && $token->nodeValue) {
                return $token->nodeValue;
            }
        }
        $dateFormat = $this->granularity == 'YYYY-MM-DD'
            ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
        $this->saveLastHarvestedDate(date($dateFormat, $this->serverDate));
        return false;
    }

    /**
     * Harvest records via OAI-PMH using date and set.
     *
     * @return mixed Resumption token if provided, false if finished
     */
    protected function getRecordsByDate()
    {
        $params = ['metadataPrefix' => $this->metadata];
        if (!empty($this->startDate)) {
            $params['from'] = $this->startDate;
        }
        if (!empty($this->endDate)) {
            $params['until'] = $this->endDate;
        }
        if (!empty($this->set)) {
            $params['set'] = $this->set;
        }
        return $this->getRecords($params);
    }

    /**
     * Harvest records via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecordsByToken($token)
    {
        return $this->getRecords(['resumptionToken' => (string)$token]);
    }

    /**
     * Get identifiers using OAI-PMH.
     *
     * @param array $params GET parameters for ListIdentifiers method.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getIdentifiers($params = [])
    {
        // Make the OAI-PMH request:
        if (empty($params)) {
            $params = ['metadataPrefix' => $this->metadata];
            if (!empty($this->set)) {
                $params['set'] = $this->set;
            }
        }

        $this->xml = $this->sendRequest('ListIdentifiers', $params);

        // Process headers
        $listIdentifiers = $this->getSingleNode($this->xml, 'ListIdentifiers');
        if ($listIdentifiers !== false) {
            $headers = $this->getImmediateChildrenByTagName(
                $listIdentifiers, 'header'
            );
            $this->processIdentifiers($headers);
            $token = $this->getSingleNode($listIdentifiers, 'resumptionToken');
            if ($token !== false && $token->nodeValue) {
                return $token->nodeValue;
            }
        }

        return false;
    }

    /**
     * Get identifiers via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getIdentifiersByToken($token)
    {
        return $this->getIdentifiers(['resumptionToken' => (string)$token]);
    }

    /**
     * Process fetched identifiers.
     *
     * @param object $headers SimpleXML headers
     *
     * @return void
     */
    protected function processIdentifiers($headers)
    {
        $this->message('Processing ' . count($headers) . ' identifiers', true);

        // Loop through the records:
        foreach ($headers as $header) {
            // Get the ID of the current record:
            $id = $this->extractID($header);

            // Process the current header, either as a deleted or as a regular record
            if (strcasecmp($header->getAttribute('status'), 'deleted') == 0) {
                call_user_func($this->callback, $this->source, $id, true);
                $this->deletedRecords++;
            } else {
                call_user_func($this->callback, $this->source, $id, false);
                $this->changedRecords++;
            }
        }
    }

    /**
     * Get the first XML child node with the given name
     *
     * @param DOMNode $xml      The XML Node
     * @param string  $nodeName Node to get
     *
     * @return DOMNode | false  Result node or false if not found
     */
    protected function getSingleNode($xml, $nodeName)
    {
        $nodes = $xml->getElementsByTagName($nodeName);
        if ($nodes->length == 0) {
            return false;
        }
        return $nodes->item(0);
    }

    /**
     * Traverse all children and collect those nodes that
     * have the tagname specified in $tagName. Non-recursive
     *
     * @param DOMNode $element DOM Element
     * @param string  $tagName Tag to get
     *
     * @return array
     */
    protected function getImmediateChildrenByTagName($element, $tagName)
    {
        $result = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->tagName == $tagName) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * Identify the server and setup some setting based on the response
     *
     * @return void
     */
    protected function identifyServer()
    {
        $this->message('Identifying server');
        $response = $this->sendRequest('Identify');
        if ($this->granularity == 'auto') {
            $identify = $this->getSingleNode($response, 'Identify');
            if ($identify === false) {
                throw new \Exception(
                    "{$this->source}: Could not find Identify node in the Identify"
                    . ' response'
                );
            }
            $granularity = $this->getSingleNode(
                $identify,
                'granularity'
            );
            if ($granularity === false) {
                throw new \Exception(
                    "{$this->source}: Could not find date granularity in the"
                    . ' Identify response'
                );
            }
            $this->granularity = trim(
                $granularity->nodeValue
            );
            $this->message("Detected date granularity: {$this->granularity}");
        }
        $this->serverDate = $this->normalizeDate(
            $this->getSingleNode($response, 'responseDate')->nodeValue
        );
        $this->message(
            'Current server date: ' . date('Y-m-d\TH:i:s\Z', $this->serverDate)
        );
    }
}
