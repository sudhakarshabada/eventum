<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

use Eventum\Db\AbstractMigration;

class EventumConvertConst extends AbstractMigration
{
    public function up(): void
    {
        $setup = Setup::get();

        $this->convertConstants($setup, [
            // new users will use these for default preferences
            // if the user will receive an email when an issue is assigned to him
            'APP_DEFAULT_ASSIGNED_EMAILS' => true,
            // if the user will receive an email when ANY issue is created
            'APP_DEFAULT_NEW_EMAILS' => false,
            'APP_DEFAULT_COPY_OF_OWN_ACTION' => false,
            'APP_DEFAULT_PAGER_SIZE' => 5,
            'APP_DEFAULT_REFRESH_RATE' => 5,
            // timezone for displayed times in web and emails
            'APP_DEFAULT_TIMEZONE' => 'UTC',
            // default day of week start: 0 = sunday; 1 = monday
            'APP_DEFAULT_WEEKDAY' => 1,
            // 'native' or 'php'. Try native first, if you experience strange issues
            // such as language switching randomly, try php
            'APP_GETTEXT_MODE' => 'native',
            // directory where to save routed drafts/notes/emails. use NULL or '' to disable.
            'APP_ROUTED_MAILS_SAVEDIR' => null,
            // define colors used by eventum
            'APP_INTERNAL_COLOR' => '#9C494B',
            // locale used for localized messages
            'APP_DEFAULT_LOCALE' => 'en_US',
            // if full text searching is enabled
            'APP_ENABLE_FULLTEXT' => false,
            'APP_FULLTEXT_SEARCH_CLASS' => 'mysql_fulltext_search',
        ]);

        $toolCaption = $setup['tool_caption'] ?: APP_NAME;
        $setup['tool_caption'] = $toolCaption;

        Setup::save();
    }

    public function down(): void
    {
    }

    private function convertConstants($setup, $constants): void
    {
        foreach ($constants as $constName => $defaultValue) {
            $value = defined($constName) ? constant($constName) : $defaultValue;
            $key = strtolower(str_replace('APP_', '', $constName));

            $setup[$key] = $value;
        }
    }
}
