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

namespace Eventum\Mail;

use Setup;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class MailDumper
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_DRAFT = 'draft';
    public const TYPE_NOTE = 'note';

    /**
     * Method used to save the routed mail into a backup directory.
     *
     * @throws IOException
     */
    public static function dump(MailMessage $mail, string $type): void
    {
        $filename = static::getFilename($type);
        if (!$filename) {
            return;
        }

        $fs = new Filesystem();
        $fs->dumpFile($filename, $mail->getRawContent());
        $fs->chmod($filename, 0644);
    }

    private static function getFilename(string $type)
    {
        $path = Setup::get()['routed_mails_savedir'];
        if (!$path) {
            return null;
        }

        $dirMap = [
            'email' => 'routed_emails',
            'draft' => 'routed_drafts',
            'note' => 'routed_notes',
        ];
        $nameMap = [
            // value 'note' here is incorrect
            // but keeping backward compat
            'email' => 'note',
            'draft' => 'draft',
            'note' => 'note',
        ];

        [$usec, $timestamp] = explode(' ', microtime());

        return sprintf(
            "%s/{$dirMap[$type]}/%s{$usec}.{$nameMap[$type]}.txt",
            $path,
            date('Y-m-d_H-i-s_', $timestamp)
        );
    }
}
