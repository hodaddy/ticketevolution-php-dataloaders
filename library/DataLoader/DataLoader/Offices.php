<?php

/**
 * LICENSE
 *
 * This source file is subject to the new BSD (3-Clause) License that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://choosealicense.com/licenses/bsd-3-clause/
 *
 * @category    DataLoader
 * @package     DataLoader\DataLoader
 * @copyright   Copyright (c) 2013 J Cobb. (http://jcobb.org)
 * @license     http://choosealicense.com/licenses/bsd-3-clause/ BSD (3-Clause) License
 */


namespace DataLoader\DataLoader;
use DataLoader\DataLoader\AbstractDataLoader;
use DataLoader\Db\Table\OfficeEmails as OfficeEmailsTable;
use DataLoader\Db\Table\OfficeHours as OfficeHoursTable;
use TicketEvolution\DateTime;


/**
 * DataLoader for a specific API endpoint to cache the data into local table(s)
 *
 * @category    DataLoader
 * @package     DataLoader\DataLoader
 * @copyright   Copyright (c) 2013 J Cobb. (http://jcobb.org)
 * @license     http://choosealicense.com/licenses/bsd-3-clause/ BSD (3-Clause) License
 */
class Offices extends AbstractDataLoader
{
    /**
     * Which endpoint we are hitting. This is used in the `dataLoaderStatus` table
     *
     * @var string
     */
    var $endpoint = 'offices';


    /**
     * The type of items to get [active|deleted]
     *
     * @var string
     */
    var $endpointState = 'active';


    /**
     * The \TicketEvolution\Webservice method to use for the API request
     *
     * @var string
     */
    protected $_webServiceMethod = 'listOffices';


    /**
     * The class of the table
     *
     * @var Zend_Db_Table
     */
    protected $_tableClass = '\DataLoader\Db\Table\Offices';


    /**
     * Manipulates the $result data into an array to be passed to the table row
     *
     * @param object $result    The current result item
     * @return void
     */
    protected function _formatData($result)
    {
        $this->_data = array(
            'officeId'                  => (int)    $result->id,
            'brokerageId'               => (int)    $result->brokerage->id,
            'officeName'                => (string) $result->name,
            'phone'                     => (string) $result->phone,
            'fax'                       => (string) $result->fax,
            'timezone'                  => (string) $result->time_zone,
            'isMain'                    => (int)    $result->main,
            'isPos'                     => (int)    $result->pos,
            'evoPayDiscount'            => (float)  $result->evopay_discount,
            'officeUrl'                 => (string) $result->url,
            'updated_at'                => (string) $result->updated_at,
            'officesStatus'             => (int)    1,

            'created_at'                => null,
            'deleted_at'                => null,
            'streetAddress'             => null,
            'extendedAddress'           => null,
            'locality'                  => null,
            'region'                    => null,
            'postalCode'                => null,
            'countryCode'               => null,
            'isPoBox'                   => null,
            'latitude'                  => null,
            'longitude'                 => null,
        );

        if (!empty($result->created_at)) {
            $this->_data['created_at'] = (string) $result->created_at;
        }

        if (!empty($result->deleted_at)) {
            $this->_data['deleted_at'] = (string) $result->deleted_at;
        }

        if (isset($result->address)) {
            $this->_data['streetAddress']   = (string) $result->address->street_address;
            $this->_data['extendedAddress'] = (string) $result->address->extended_address;
            $this->_data['locality']        = (string) $result->address->locality;
            $this->_data['region']          = (string) $result->address->region;
            $this->_data['postalCode']      = (string) $result->address->postal_code;
            $this->_data['countryCode']     = (string) $result->address->country_code;
            $this->_data['isPoBox']         = (int)    $result->address->po_box;
            $this->_data['latitude']        = (float)  $result->address->latitude;
            $this->_data['longitude']       = (float)  $result->address->longitude;
        }
    }


    /**
     * Allows pre-save logic to be applied.
     * Subclasses may override this method.
     *
     * @param object $result    The current result item. Only passed to enable progress output
     * @return void
     */
    protected function _preSave($result)
    {
    }


    /**
     * Allows post-save logic to be applied.
     * Subclasses may override this method.
     *
     * @param object $result    The current result item
     * @return void
     */
    protected function _postSave($result)
    {
        // Save any email addresses to a separate table
        if (isset($result->email[0])) {
            // Create an object for the `tevoOfficeEmails` table
            $emailsTable = new OfficeEmailsTable();

            // Initialize an array of emails
            $emailArray = array();

            // Loop through the emails and add them to the `tevoOfficeEmails` table
            foreach ($result->email as $email) {
                $data = array(
                    'officeId'              => (int)    $result->id,
                    'email'                 => strtolower((string)$email),
                    'officeEmailsStatus'    => (int)    1,
                    'lastModifiedDate'      => (string) $this->_startTime->format('c'),
                );

                if ($row = $emailsTable->fetchRow($emailsTable->select()->where("`officeId` = ?", $data['officeId'])->where("`email` = ?", $data['email']))) {
                    $row->setFromArray($data);
                } else {
                    $row = $emailsTable->createRow($data);
                }
                $row->save();

                unset($row);
                $emailArray[] = $data['email'];
                unset($data);
            } // End loop through emails for this office

            if ($this->_showProgress) {
                echo '<p>Saved ' . implode(', ', $emailArray) . ' to `tevoOfficeEmails` for this office</p>' . PHP_EOL;
            }

            // Now set `officeEmailsStatus` = 0 for any `tevoOfficeEmails` entries for
            // this office that are not in $emailArray. This will set any emails that
            // were attached to this office but are no longer to false/inactive
            if (isset($emailArray)) {
                $data = array(
                    'officeEmailsStatus' => (int)    0,
                    'lastModifiedDate'  => (string) $this->_startTime->format('c'),
                );
                $where = $emailsTable->getAdapter()->quoteInto("`officeId` = ?", $result->id);
                $where .= $emailsTable->getAdapter()->quoteInto(" AND `officeEmailsStatus` = ?", (int) 1);
                $where .= $emailsTable->getAdapter()->quoteInto(" AND `email` NOT IN (?)", $emailArray);
                $emailsTable->update($data, $where);
                unset($data);
                unset($where);
                unset($emailArray);
            }
        }


        // Save any office hours to a separate table
        if (isset($result->hours[0])) {
            // Create an object for the `tevoOfficeHours` table
            $hoursTable = new OfficeHoursTable();

            // Initialize an array of emails
            $hoursIdArray = array();

            // Loop through the emails and add them to the `tevoOfficeEmails` table
            foreach ($result->hours as $hour) {
                $openTime = new DateTime($hour->open);
                $closeTime = new DateTime($hour->close);

                $data = array(
                    'officeHoursId'     => (int)    $hour->id,
                    'officeId'          => (int)    $result->id,
                    'dayOfWeek'         => (int)    $hour->day_of_week,
                    'isClosed'          => (int)    $hour->closed,
                    'open'              => (string) $openTime->format(DateTime::MYSQL_TIME),
                    'close'             => (string) $closeTime->format(DateTime::MYSQL_TIME),
                    'officeHoursStatus' => (int)    1,
                    'lastModifiedDate'  => (string) $this->_startTime->format('c'),
                );

                if ($row = $hoursTable->fetchRow($hoursTable->select()->where("`officeHoursId` = ?", $data['officeHoursId']))) {
                    $row->setFromArray($data);
                } else {
                    $row = $hoursTable->createRow($data);
                }
                $row->save();

                unset($row);
                $hoursIdArray[] = $data['officeHoursId'];
                unset($data);
            } // End loop through hours for this office

            if ($this->_showProgress) {
                echo '<p>Saved ' . implode(', ', $hoursIdArray) . ' to `tevoOfficeHours` for this office</p>' . PHP_EOL;
            }

            // Now set `officeHoursStatus` = 0 for any `tevoOfficeHours` entries for
            // this office that are not in $hoursIdArray. This will set any hours that
            // were attached to this office but are no longer to false/inactive
            if (isset($hoursIdArray)) {
                $data = array(
                    'officeHoursStatus' => (int)    0,
                    'lastModifiedDate'  => (string) $this->_startTime->format('c'),
                );
                $where = $hoursTable->getAdapter()->quoteInto("`officeId` = ?", $result->id);
                $where .= $hoursTable->getAdapter()->quoteInto(" AND `officeHoursStatus` = ?", (int) 1);
                $where .= $hoursTable->getAdapter()->quoteInto(" AND `officeHoursId` NOT IN (?)", $hoursIdArray);
                $hoursTable->update($data, $where);
                unset($data);
                unset($where);
                unset($hoursIdArray);
            }
        }
    }


}
