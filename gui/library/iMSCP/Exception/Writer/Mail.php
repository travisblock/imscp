<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2017 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * Class iMSCP_Exception_Writer_Mail
 *
 * This exception writer writes an exception messages to admin email.
 */
class iMSCP_Exception_Writer_Mail extends iMSCP_Exception_Writer_Abstract
{
    /**
     * Exception writer name
     *
     * @var string
     */
    const NAME = 'i-MSCP Exception Mail Writer';

    /**
     * onUncaughtException event listener
     *
     * @param iMSCP_Exception_Event $event
     * @return void
     * @throws Zend_Exception
     * @throws iMSCP_Events_Manager_Exception
     * @throws iMSCP_Exception
     */
    public function onUncaughtException(iMSCP_Exception_Event $event)
    {
        $data = $this->prepareMailData($event->getException());

        if (empty($data)) {
            return;
        }

        $footprintsCacheFile = CACHE_PATH . '/mail_body_footprints.php';
        $footprints = [];
        $now = time();

        // Load footprints cache file
        if (is_readable($footprintsCacheFile)) {
            $footprints = include($footprintsCacheFile);

            if (!is_array($footprints)) {
                $footprints = [];
            }
        }

        # Remove expired entries from the cache
        foreach ($footprints as $footprint => $expireTime) {
            if ($expireTime <= $now) {
                unset($footprints[$footprint]);
            }
        }

        // Do not send mail for identical exception in next 24 hours
        if (array_key_exists($data['footprint'], $footprints)) {
            return;
        }

        send_mail($data);

        // Update footprints cache file
        $footprints[$data['footprint']] = strtotime('+24 hours');
        $fileContent = "<?php\n";
        $fileContent .= "// File automatically generated by i-MSCP. Do not edit it manually.\n";
        $fileContent .= "return " . var_export($footprints, true) . ";\n";
        @file_put_contents($footprintsCacheFile, $fileContent, LOCK_EX);
        iMSCP_Utility_OpcodeCache::clearAllActive($footprintsCacheFile); // Be sure to load newest version on next call
    }

    /**
     * Prepare the mail to be send
     *
     * @param Exception $exception An exception object
     * @return array Array containing mail data
     * @throws Zend_Exception
     */
    protected function prepareMailData($exception)
    {
        $data = [];
        if (!iMSCP_Registry::isRegistered('config')) {
            return $data;
        }

        $config = iMSCP_Registry::get('config');
        if (!isset($config['DEFAULT_ADMIN_ADDRESS'])) {
            return $data;
        }

        $message = preg_replace('#([\t\n]+|<br \/>)#', ' ', $exception->getMessage());

        if ($exception instanceof iMSCP_Exception_Database) {
            $query = $exception->getQuery();
            if ($query !== '') {
                $message .= "\n\nQuery was:\n\n" . $exception->getQuery();
            }
        }

        $backtraces = '';
        if ($traces = $exception->getTrace()) {
            foreach ($traces as $trace) {
                if (isset($trace['file'])) {
                    $backtraces .= sprintf("File: %s at line %s\n", $trace['file'], $trace['line']);
                }

                if (isset($trace['class'])) {
                    $backtraces .= sprintf("Method: %s\n", $trace['class'] . '::' . $trace['function'] . '()');
                } elseif (isset($trace['function'])) {
                    $backtraces .= sprintf("Function: %s\n", $trace['function'] . '()');
                }
            }
        } else {
            $backtraces .= sprintf("File: %s at line %s\n", $exception->getFile(), $exception->getLine());
            $backtraces .= "Function: main()\n";
        }

        $contextInfo = '';
        foreach (['HTTP_USER_AGENT', 'REQUEST_URI', 'HTTP_REFERER', 'REMOTE_ADDR', 'X-FORWARDED-FOR', 'SERVER_ADDR'] as $key) {
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                $contextInfo .= ucwords(strtolower(str_replace('_', ' ', $key))) . ": {$_SERVER["$key"]}\n";
            }
        }

        return [
            'mail_id' => 'exception-notification',
            'footprint' => sha1($message),
            'username' => 'administrator',
            'email' => $config['DEFAULT_ADMIN_ADDRESS'],
            'subject' => 'i-MSCP - An exception has been thrown',
            'message' => <<<EOF
Dear {NAME},

An exception has been thrown in file {FILE} at line {LINE}:

==========================================================================
{EXCEPTION}
==========================================================================

Backtrace:
__________

{BACKTRACE}

Contextual information:
_______________________

{CONTEXT_INFO}

Note: You will not receive further emails for this exception in the next 24 hours.

Please do not reply to this email.

___________________________
i-MSCP Mailer
EOF
        ,
            'placeholders' => [
                '{FILE}' => $exception->getFile(),
                '{LINE}' => $exception->getLine(),
                '{EXCEPTION}' => $message,
                '{BACKTRACE}' => $backtraces,
                '{CONTEXT_INFO}' => $contextInfo
            ]
        ];
    }
}
