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

use Eventum\Attachment\AttachmentGroup;
use Eventum\Config\Paths;
use Eventum\Db\Doctrine;
use Eventum\Event\ResultableEvent;
use Eventum\Event\SystemEvents;
use Eventum\EventDispatcher\EventManager;
use Eventum\Extension\ExtensionLoader;
use Eventum\LinkFilter\LinkFilter;
use Eventum\Mail\Helper\AddressHeader;
use Eventum\Mail\ImapMessage;
use Eventum\Mail\MailMessage;
use Eventum\Monolog\Logger;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * @deprecated workflow backend concept is deprecated, use event subscribers
 */
class Workflow
{
    /**
     * Returns the name of the workflow backend for the specified project.
     *
     * @param   int $prj_id the id of the project to lookup
     * @throws Exception
     * @return  string the name of the customer backend
     */
    private static function _getBackendNameByProject($prj_id)
    {
        static $backends;

        if ($backends === null) {
            $stmt = 'SELECT
                    prj_id,
                    prj_workflow_backend
                 FROM
                    `project`
                 ORDER BY
                    prj_id';
            $backends = DB_Helper::getInstance()->getPair($stmt);
        }

        return $backends[$prj_id] ?? null;
    }

    /**
     * Includes the appropriate workflow backend class associated with the
     * given project ID, instantiates it and returns the class.
     *
     * @param   int $prj_id The project ID
     */
    public static function getBackend($prj_id): ?Abstract_Workflow_Backend
    {
        static $cache = [];

        $prj_id = (int)$prj_id;

        $initialize = static function (int $prj_id): ?Abstract_Workflow_Backend {
            // bunch of code calling without project id context
            if (!$prj_id) {
                return null;
            }

            $backendName = static::_getBackendNameByProject($prj_id);
            if (!$backendName) {
                return null;
            }

            try {
                /** @var Abstract_Workflow_Backend $backend */
                $backend = static::getExtensionLoader()->createInstance($backendName);
                $backend->prj_id = $prj_id;
            } catch (InvalidArgumentException $e) {
                Logger::app()->error($e->getMessage(), ['exception' => $e]);

                return null;
            }

            return $backend;
        };

        if (array_key_exists($prj_id, $cache)) {
            return $cache[$prj_id];
        }

        return $cache[$prj_id] = $initialize($prj_id);
    }

    /**
     * Checks whether the given project ID is setup to use workflow integration
     * or not.
     *
     * @param   int $prj_id The project ID
     * @return  bool
     * @deprecated this method is not used by eventum
     */
    public static function hasWorkflowIntegration($prj_id)
    {
        $backend = self::_getBackendNameByProject($prj_id);
        if (empty($backend)) {
            return false;
        }

        return true;
    }

    /**
     * Is called when an issue is updated.
     *
     * @param   int $prj_id the project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id the ID of the user
     * @param   array $old_details the old details of the issues
     * @param   array $raw_post The changes that were applied to this issue (the $_POST)
     * @param array $updated_fields
     * @param array $updated_custom_fields
     * @since 3.5.0 emits ISSUE_UPDATED event
     */
    public static function handleIssueUpdated($prj_id, $issue_id, $usr_id, $old_details, $raw_post, $updated_fields, $updated_custom_fields): void
    {
        Partner::handleIssueChange($issue_id, $usr_id, $old_details, $raw_post);

        $arguments = [
            'issue_id' => (int)$issue_id,
            'prj_id' => (int)$prj_id,
            'usr_id' => (int)$usr_id,
            'issue_details' => Issue::getDetails($issue_id, true),
            'updated_fields' => $updated_fields,
            'updated_custom_fields' => $updated_custom_fields,
            'old_details' => $old_details,
            'raw_post' => $raw_post,
        ];
        EventManager::dispatch(SystemEvents::ISSUE_UPDATED, new GenericEvent(null, $arguments));

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }

        $backend->handleIssueUpdated($prj_id, $issue_id, $usr_id, $old_details, $raw_post);
    }

    /**
     * Called before an issue is updated.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id The ID of the issue
     * @param   int $usr_id the ID of the user changing the issue
     * @param   array $changes
     * @return  mixed. True to continue, anything else to cancel the change and return the value
     * @since 3.5.0 emits ISSUE_CREATED_BEFORE event
     * @todo port to ResultableEvent
     */
    public static function preIssueUpdated($prj_id, $issue_id, $usr_id, &$changes, $issue_details)
    {
        $arguments = [
            'issue_id' => (int)$issue_id,
            'prj_id' => (int)$prj_id,
            'usr_id' => (int)$usr_id,
            'issue_details' => $issue_details,
            'changes' => $changes,
            // 'true' to continue, anything else to cancel the change and return the value
            'bubble' => true,
        ];

        $event = EventManager::dispatch(SystemEvents::ISSUE_UPDATED_BEFORE, new GenericEvent(null, $arguments));

        if ($event['bubble'] !== true) {
            return $event['bubble'];
        }

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return true;
        }

        return $backend->preIssueUpdated($prj_id, $issue_id, $usr_id, $changes);
    }

    /**
     * Called when a file is attached to an issue..
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id the id of the user who attached this file
     * @param   AttachmentGroup $attachment_group The attachment object
     */
    public static function handleAttachment($prj_id, $issue_id, $usr_id, AttachmentGroup $attachment_group): void
    {
        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }

        $backend->handleAttachment($prj_id, $issue_id, $usr_id, $attachment_group);
    }

    /**
     * Determines if the attachment should be added
     *
     * @param   int $prj_id the project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id The id of the user who attached the file
     * @param   array $attachment attachment object
     * @return  bool
     * @since 3.6.3 emits ATTACHMENT_ATTACH_FILE event
     * @deprecated
     */
    public static function shouldAttachFile(int $prj_id, int $issue_id, $usr_id, array $attachment): bool
    {
        $arguments = [
            'prj_id' => $prj_id,
            'issue_id' => $issue_id,
            'usr_id' => is_numeric($usr_id) ? (int)$usr_id : $usr_id,
        ];
        $event = new ResultableEvent($attachment, $arguments);
        EventManager::dispatch(SystemEvents::ATTACHMENT_ATTACH_FILE, $event);
        if ($event->hasResult()) {
            return $event->getResult();
        }

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return true;
        }

        return $backend->shouldAttachFile($prj_id, $issue_id, $usr_id, $attachment);
    }

    /**
     * Called when the priority of an issue changes.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id the id of the user who changed the issue
     * @param   array $old_details the old details of the issue
     * @param   array $changes The changes that were applied to this issue (the $_POST)
     */
    public static function handlePriorityChange($prj_id, $issue_id, $usr_id, $old_details, $changes): void
    {
        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handlePriorityChange($prj_id, $issue_id, $usr_id, $old_details, $changes);
    }

    /**
     * Called when the severity of an issue changes.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id the id of the user who changed the issue
     * @param   array $old_details the old details of the issue
     * @param   array $changes The changes that were applied to this issue (the $_POST)
     */
    public static function handleSeverityChange($prj_id, $issue_id, $usr_id, $old_details, $changes): void
    {
        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleSeverityChange($prj_id, $issue_id, $usr_id, $old_details, $changes);
    }

    /**
     * Called when an email is blocked.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   array $email_details Details of the issue
     * @param   string $type what type of blocked email this is
     * @param MailMessage $mail
     * @since 3.4.2 emits BLOCKED_EMAIL event
     * @deprecated use SystemEvents::EMAIL_BLOCKED event listener
     */
    public static function handleBlockedEmail($prj_id, $issue_id, $email_details, $type, $mail = null): void
    {
        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => (int)$issue_id,
            'email_details' => $email_details,
            'type' => $type,
            'mail' => $mail,
        ];
        $event = new GenericEvent(null, $arguments);
        EventManager::dispatch(SystemEvents::EMAIL_BLOCKED, $event);

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleBlockedEmail($prj_id, $issue_id, $email_details, $type);
    }

    /**
     * Called when the assignment on an issue changes.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id the id of the user who assigned the issue
     * @param   array $issue_details the old details of the issue
     * @param   array $new_assignees the new assignees of this issue
     * @param   bool $remote_assignment if this issue was remotely assigned
     * @since 3.4.2 emits ISSUE_ASSIGNMENT_CHANGE event
     * @deprecated since 3.4.2
     */
    public static function handleAssignmentChange($prj_id, $issue_id, $usr_id, $issue_details, $new_assignees, $remote_assignment = false): void
    {
        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => (int)$issue_id,
            'usr_id' => (int)$usr_id,
            'issue_details' => $issue_details,
            'new_assignees' => $new_assignees,
            'remote_assignment' => $remote_assignment,
        ];

        $event = new GenericEvent(null, $arguments);
        EventManager::dispatch(SystemEvents::ISSUE_ASSIGNMENT_CHANGE, $event);

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleAssignmentChange($prj_id, $issue_id, $usr_id, $issue_details, $new_assignees, $remote_assignment);
    }

    /**
     * Called when a new issue is created.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   bool $has_TAM if this issue has a technical account manager
     * @param   bool $has_RR if Round Robin was used to assign this issue
     * @since 3.5.0 emits ISSUE_CREATED event
     */
    public static function handleNewIssue($prj_id, $issue_id, $has_TAM, $has_RR): void
    {
        $usr_id = Auth::getUserID() ?: Setup::get()['system_user_id'];
        $arguments = [
            'issue_id' => (int)$issue_id,
            'prj_id' => (int)$prj_id,
            'usr_id' => (int)$usr_id,
            'has_TAM' => $has_TAM,
            'has_RR' => $has_RR,
            'issue_details' => Issue::getDetails($issue_id),
        ];
        EventManager::dispatch(SystemEvents::ISSUE_CREATED, new GenericEvent(null, $arguments));

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleNewIssue($prj_id, $issue_id, $has_TAM, $has_RR);
    }

    /**
     * Called when an email is received.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   MailMessage $mail The Mail object
     * @param   array $row the array of data that was inserted into the database
     * @param   bool $closing if we are closing the issue
     * @since 3.4.2 emits MAIL_PENDING event
     * @deprecated since 3.4.2
     * @see Support::moveEmail
     * @see Support::insertEmail
     * @since 3.7.0 adds 'issue' argument to event
     */
    public static function handleNewEmail(int $prj_id, int $issue_id, MailMessage $mail, $row, $closing = false): void
    {
        Partner::handleNewEmail($issue_id, $row['sup_id']);

        // there are more variable options in $row
        // add just useful ones for event handler
        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => (int)$issue_id,
            'issue' => Doctrine::getIssueRepository()->findById($issue_id),
            'closing' => (bool)$closing,
            'customer_id' => $row['customer_id'] ?? null,
            'contact_id' => $row['contact_id'] ?? null,
            'ema_id' => $row['ema_id'] ?? null,
            'sup_id' => $row['sup_id'] ?? null,
            'should_create_issue' => $row['should_create_issue'] ?? null,
            'data' => $row,
        ];

        if (empty($row['issue_id'])) {
            $event = new GenericEvent($mail, $arguments);
            EventManager::dispatch(SystemEvents::MAIL_PENDING, $event);
        }

        $event = new GenericEvent($mail, $arguments);
        EventManager::dispatch(SystemEvents::MAIL_CREATED, $event);

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleNewEmail($prj_id, $issue_id, $mail, $row, $closing);
    }

    /**
     * Called when an email is manually associated with an existing issue.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     */
    public static function handleManualEmailAssociation($prj_id, $issue_id): void
    {
        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleManualEmailAssociation($prj_id, $issue_id);
    }

    /**
     * Called when a note is routed.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   int $usr_id The user ID of the person posting this new note
     * @param   bool $closing If the issue is being closed
     * @param   int $note_id The ID of the new note
     * @since 3.5.0 emits NOTE_CREATED event
     * @since 3.7.0 adds 'issue' argument to event
     */
    public static function handleNewNote(int $prj_id, int $issue_id, $usr_id, $closing, $note_id): void
    {
        Partner::handleNewNote($issue_id, $note_id);

        $arguments = [
            'issue_id' => (int)$issue_id,
            'issue' => Doctrine::getIssueRepository()->findById($issue_id),
            'prj_id' => (int)$prj_id,
            'usr_id' => (int)$usr_id,
            'note_id' => (int)$note_id,
            'note_details' => Note::getDetails($note_id),
            'closing' => (bool)$closing,
        ];
        EventManager::dispatch(SystemEvents::NOTE_CREATED, new GenericEvent(null, $arguments));

        $backend = self::getBackend($prj_id);
        if (!$backend) {
            return;
        }
        $backend->handleNewNote($prj_id, $issue_id, $usr_id, $closing, $note_id);
    }

    /**
     * Method is called to return the list of statuses valid for a specific issue.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @return  array an associative array of statuses valid for this issue
     * @since 3.6.3 emits ISSUE_ALLOWED_STATUSES event
     * @since 3.6.4 adds Status::getAssocStatusList as Subject
     * @deprecated
     */
    public static function getAllowedStatuses($prj_id, $issue_id = null): array
    {
        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => $issue_id ? (int)$issue_id : null,
        ];

        $statusList = Status::getAssocStatusList($prj_id, false);
        $event = new ResultableEvent($statusList, $arguments);
        EventManager::dispatch(SystemEvents::ISSUE_ALLOWED_STATUSES, $event);
        if ($event->hasResult()) {
            return $event->getResult();
        }

        if ($backend = self::getBackend($prj_id)) {
            $statusList = $backend->getAllowedStatuses($prj_id, $issue_id);
        }

        return $statusList;
    }

    /**
     * Called when issue is closed.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   bool $send_notification Whether to send a notification about this action or not
     * @param   int $resolution_id The resolution ID
     * @param   int $status_id The status ID
     * @param   string $reason The reason for closing this issue
     * @param   int $usr_id The ID of the user closing this issue
     * @since 3.4.2 emits ISSUE_CLOSED event
     * @deprecated since 3.4.2
     */
    public static function handleIssueClosed($prj_id, $issue_id, $send_notification, $resolution_id, $status_id, $reason, $usr_id): void
    {
        $issue_details = Issue::getDetails($issue_id, true);

        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => (int)$issue_id,
            'send_notification' => $send_notification,
            'resolution_id' => (int)$resolution_id,
            'status_id' => (int)$status_id,
            'reason' => $reason,
            'usr_id' => (int)$usr_id,
            'issue_details' => $issue_details,
        ];

        $event = new GenericEvent(null, $arguments);
        EventManager::dispatch(SystemEvents::ISSUE_CLOSED, $event);

        if (!$backend = self::getBackend($prj_id)) {
            return;
        }
        $backend->handleIssueClosed($prj_id, $issue_id, $send_notification, $resolution_id, $status_id, $reason, $usr_id);
    }

    /**
     * Called when custom fields are updated
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id The ID of the issue
     * @param   array $old the custom fields before the update
     * @param   array $new the custom fields after the update
     * @param   array $changed an array containing what was changed
     */
    public static function handleCustomFieldsUpdated($prj_id, $issue_id, $old, $new, $changed): void
    {
        if (!$backend = self::getBackend($prj_id)) {
            return;
        }

        $backend->handleCustomFieldsUpdated($prj_id, $issue_id, $old, $new, $changed);
    }

    /**
     * Called when an attempt is made to add a user or email address to the
     * notification list.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id the ID of the issue
     * @param   int|bool $subscriber_usr_id the ID of the user to subscribe if this is a real user (false otherwise)
     * @param   string $email the email address  to subscribe (if this is not a real user)
     * @param   array $actions the action types
     * @return  array|bool|null an array of information or true to continue unchanged or false to prevent the user from being added
     * @since 3.6.3 emits NOTIFICATION_HANDLE_SUBSCRIPTION event
     * @since 3.6.4 add 'address' property of type Address
     * @deprecated
     */
    public static function handleSubscription($prj_id, $issue_id, &$subscriber_usr_id, &$email, &$actions)
    {
        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => (int)$issue_id,
            'subscriber_usr_id' => is_numeric($subscriber_usr_id) ? (int)$subscriber_usr_id : $subscriber_usr_id,
            'email' => $email, // @deprecated, use 'address' instead
            'address' => $email ? AddressHeader::fromString($email)->getAddress() : null,
            'actions' => $actions,
        ];

        $event = new ResultableEvent(null, $arguments);
        EventManager::dispatch(SystemEvents::NOTIFICATION_HANDLE_SUBSCRIPTION, $event);

        // assign back, in case these were changed
        $subscriber_usr_id = $event['subscriber_usr_id'];
        $email = $event['email'];
        $actions = $event['actions'];

        if ($event->hasResult()) {
            return $event->getResult();
        }

        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->handleSubscription($prj_id, $issue_id, $subscriber_usr_id, $email, $actions);
    }

    /**
     * Determines if the address should should be emailed.
     *
     * @param int $prj_id the project ID
     * @param string $address The email address to check
     * @param bool $issue_id
     * @param bool $type
     * @since 3.6.0 emits NOTIFICATION_NOTIFY_ADDRESS event
     * @return bool
     * @todo https://github.com/eventum/eventum/pull/438#issuecomment-452706697
     */
    public static function shouldEmailAddress($prj_id, $address, $issue_id = false, $type = false)
    {
        $arguments = [
            'prj_id' => (int)$prj_id,
            'issue_id' => $issue_id ? (int)$issue_id : null,
            'address' => AddressHeader::fromString($address)->getAddress(),
            'type' => $type ? $type : null,
        ];

        $event = new ResultableEvent(null, $arguments);
        EventManager::dispatch(SystemEvents::NOTIFICATION_NOTIFY_ADDRESS, $event);

        if ($event->hasResult()) {
            return $event->getResult();
        }

        if (!$backend = self::getBackend($prj_id)) {
            return true;
        }

        return $backend->shouldEmailAddress($prj_id, $address, $issue_id, $type);
    }

    /**
     * Returns additional email addresses that should be notified for a specific event..
     *
     * @param   int $prj_id the project ID
     * @param   int $issue_id the ID of the issue
     * @param   string $event The event to return additional email addresses for. Currently only "new_issue" is supported.
     * @param   array $extra Extra information, contains different info depending on where it is called from
     * @return  array   an array of email addresses to be notified
     */
    public static function getAdditionalEmailAddresses($prj_id, $issue_id, $event, $extra = false)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return [];
        }

        return $backend->getAdditionalEmailAddresses($prj_id, $issue_id, $event, $extra);
    }

    /**
     * Indicates if the the specified email address can email the issue. Can be
     * used to disable email blocking by always returning true.
     *
     * @param   int $prj_id the project ID
     * @param   int $issue_id The ID of the issue
     * @param   string $email The email address that is trying to send an email
     * @return  bool true if the sender can email the issue, false if the sender
     *          should not email the issue and null if the default rules should be used
     */
    public static function canEmailIssue($prj_id, $issue_id, $email)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canEmailIssue($prj_id, $issue_id, $email);
    }

    /**
     * Called to check if an email address that does not have an eventum account can send notes to an issue.
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id The issue ID
     * @param string $sender_email The email address to check
     * @param MailMessage $mail
     * @return  bool True if the note should be added, false otherwise
     */
    public static function canSendNote($prj_id, $issue_id, $sender_email, MailMessage $mail)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canSendNote($prj_id, $issue_id, $sender_email, $mail);
    }

    /**
     * Called to check if a user can clone an issue
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id The issue ID
     * @param   string $usr_id The ID of the user
     * @return  bool True if the issue can be cloned, false otherwise
     */
    public static function canCloneIssue($prj_id, $issue_id, $usr_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canCloneIssue($prj_id, $issue_id, $usr_id);
    }

    /**
     * Called to check if a user is allowed to edit the security settings of an issue
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id The issue ID
     * @param   string $usr_id The ID of the user
     * @return  bool True if the issue can be cloned, false otherwise
     */
    public static function canChangeAccessLevel($prj_id, $issue_id, $usr_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canChangeAccessLevel($prj_id, $issue_id, $usr_id);
    }

    /**
     * Handles when an authorized replier is added
     *
     * @param   int $prj_id The project ID
     * @param   int $issue_id The ID of the issue
     * @param   string $email The email address added
     * @return  bool
     */
    public static function handleAuthorizedReplierAdded($prj_id, $issue_id, &$email)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->handleAuthorizedReplierAdded($prj_id, $issue_id, $email);
    }

    /**
     * Called at the beginning of the email download process.
     * If false is returned, email should not processed further.
     *
     * @param   int $prj_id The project ID
     * @param   ImapMessage $mail The Imap Mail Message object
     * @return  mixed null by default, -1 if the rest of the email script should not be processed
     * @deprecated since 3.8.11 use ISSUE_UPDATED_BEFORE event
     */
    public static function preEmailDownload($prj_id, ImapMessage $mail): bool
    {
        $arguments = [
            'prj_id' => (int)$prj_id,
        ];

        $event = EventManager::dispatch(SystemEvents::MAIL_PROCESS_BEFORE, new GenericEvent($mail, $arguments));
        if ($event->isPropagationStopped()) {
            return false;
        }

        if (!$backend = self::getBackend($prj_id)) {
            return true;
        }

        return $backend->preEmailDownload($prj_id, $mail) !== -1;
    }

    /**
     * Called before inserting a note. If it returns false the rest of the note code
     * will not be executed. Return null to continue as normal (possibly with changed $data)
     *
     * @param   int $prj_id
     * @param   int $issue_id
     * @param   array $data
     * @return  mixed   Null by default, false if the note should not be inserted
     */
    public static function preNoteInsert($prj_id, $issue_id, &$data)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->preNoteInsert($prj_id, $issue_id, $data);
    }

    /**
     * Indicates if the email addresses should automatically be added to the NL from notes and emails.
     *
     * @param   int $prj_id the project ID
     * @return  bool
     */
    public static function shouldAutoAddToNotificationList($prj_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return true;
        }

        return $backend->shouldAutoAddToNotificationList($prj_id);
    }

    /**
     * Returns the issue ID to associate a new email with, null to use the default logic and "new" to create
     * a new issue.
     * Can also return an array containing 'customer_id', 'contact_id' and 'contract_id', 'sev_id'
     *
     * @param   int $prj_id The ID of the project
     * @param   array $info an array of info about the email account
     * @param   MailMessage $mail The Mail object
     * @return  string|array
     */
    public static function getIssueIDForNewEmail($prj_id, $info, MailMessage $mail)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->getIssueIDforNewEmail($prj_id, $info, $mail);
    }

    /**
     * Modifies the content of the message being added to the mail queue.
     *
     * @param   int $prj_id
     * @param   string $recipient
     * @param MailMessage $mail The Mail object
     * @param array $options Optional options, see Mail_Queue::queue
     * @since 3.3.0 the method signature changed
     */
    public static function modifyMailQueue($prj_id, $recipient, MailMessage $mail, $options): void
    {
        if (!$backend = self::getBackend($prj_id)) {
            return;
        }

        $backend->modifyMailQueue($prj_id, $recipient, $mail, $options);
    }

    /**
     * Called before the status changes. Parameters are passed by reference so the values can be changed.
     *
     * @param   int $prj_id
     * @param   int $issue_id
     * @param   int $status_id
     * @param   bool $notify
     * @return  bool true to continue normal processing, anything else to cancel and return value
     */
    public static function preStatusChange($prj_id, &$issue_id, &$status_id, &$notify)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return true;
        }

        return $backend->preStatusChange($prj_id, $issue_id, $status_id, $notify);
    }

    /**
     * Called at the start of many pages. After the includes and maybe some other code this
     * method is called to do whatever you want. Eventually this will be called on many pages.
     *
     * @param   int $prj_id The project ID
     * @param   string $page_name The name of the page
     */
    public static function prePage($prj_id, $page_name)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return true;
        }

        return $backend->prePage($prj_id, $page_name);
    }

    /**
     * Called to determine which actions to subscribe a new user too.
     *
     * @see     Notification::getDefaultActions()
     * @param   int $prj_id The project ID
     * @param   int $issue_id The ID of the issue
     * @param   string $email The email address of the user being added
     * @param   string $source The source of this call
     * @return  array   an array of actions
     */
    public static function getNotificationActions($prj_id, $issue_id, $email, $source)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->getNotificationActions($prj_id, $issue_id, $email, $source);
    }

    /**
     * Returns which "issue fields" should be displayed in a given location.
     *
     * @see     class.issue_field.php
     * @param   int $prj_id The project ID
     * @param   int $issue_id The ID of the issue
     * @param   string $location The location to display these fields at
     * @return  array   an array of fields to display and their associated options
     */
    public static function getIssueFieldsToDisplay($prj_id, $issue_id, $location)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return [];
        }

        return $backend->getIssueFieldsToDisplay($prj_id, $issue_id, $location);
    }

    /**
     * Updates filters in $filters.
     *
     * @since 3.6.3 emits ISSUE_LINK_FILTERS event
     * @deprecated
     */
    public static function addLinkFilters(LinkFilter $linkFilter, int $prj_id): void
    {
        $arguments = [
            'prj_id' => $prj_id,
        ];
        $event = new GenericEvent($linkFilter, $arguments);
        EventManager::dispatch(SystemEvents::ISSUE_LINK_FILTERS, $event);

        if (!$backend = self::getBackend($prj_id)) {
            return;
        }

        $linkFilter->addRules($backend->getLinkFilters($prj_id));
    }

    /**
     * Returns if a user can update an issue. Return null to use default rules.
     */
    public static function canUpdateIssue($prj_id, $issue_id, $usr_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canUpdateIssue($prj_id, $issue_id, $usr_id);
    }

    /**
     * Returns if a user can change the assignee of an issue. Return null to use default rules.
     */
    public static function canChangeAssignee($prj_id, $issue_id, $usr_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canChangeAssignee($prj_id, $issue_id, $usr_id);
    }

    /**
     * Returns the ID of the group that is "active" right now.
     */
    public static function getActiveGroup($prj_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->getActiveGroup($prj_id);
    }

    /**
     * Returns an array of additional access levels an issue can be set to
     *
     * @param $prj_id
     * @return array
     */
    public static function getAccessLevels($prj_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return [];
        }

        return $backend->getAccessLevels($prj_id);
    }

    /**
     * Performs additional checks on if a user can access an issue.
     *
     * @param $prj_id
     * @param $issue_id
     * @param $usr_id
     * @return mixed null to use default rules, true or false otherwise
     */
    public static function canAccessIssue($prj_id, $issue_id, $usr_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->canAccessIssue($prj_id, $issue_id, $usr_id);
    }

    /**
     * Returns custom SQL to limit what results a user can see on the list issues page
     *
     * @param $prj_id
     * @param $usr_id
     * @return mixed null to use default rules or an sql string otherwise
     */
    public static function getAdditionalAccessSQL($prj_id, $usr_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        return $backend->getAdditionalAccessSQL($prj_id, $usr_id);
    }

    /**
     * Called when an issue is moved from this project to another.
     *
     * @param $prj_id integer
     * @param $issue_id integer
     * @param $new_prj_id integer
     * @since 3.1.7
     */
    public static function handleIssueMovedFromProject($prj_id, $issue_id, $new_prj_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        $backend->handleIssueMovedFromProject($prj_id, $issue_id, $new_prj_id);
    }

    /**
     * Called when an issue is moved to this project from another.
     *
     * @param $prj_id integer
     * @param $issue_id integer
     * @param $old_prj_id integer
     * @since 3.1.7
     */
    public static function handleIssueMovedToProject($prj_id, $issue_id, $old_prj_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return null;
        }

        $backend->handleIssueMovedToProject($prj_id, $issue_id, $old_prj_id);
    }

    /**
     * Returns fields to be updated when an issue is moved from one project to another.
     *
     * @param $prj_id integer The ID of the project the issue is being moved to
     * @param $issue_id integer
     * @param $mapping array a key/value array containing default mappings
     * @param $old_prj_id integer The ID of the project the issue is being moved from
     * @return array A key/value array with the keys being field names in the issue table
     * @since 3.1.7
     */
    public static function getMovedIssueMapping($prj_id, $issue_id, $mapping, $old_prj_id)
    {
        if (!$backend = self::getBackend($prj_id)) {
            return $mapping;
        }

        return $backend->getMovedIssueMapping($prj_id, $issue_id, $mapping, $old_prj_id);
    }

    /**
     * @internal
     */
    public static function getExtensionLoader(): ExtensionLoader
    {
        $localPath = Setup::get()['local_path'];

        $dirs = [
            Paths::APP_INC_PATH . '/workflow',
            $localPath . '/workflow',
        ];

        return new ExtensionLoader($dirs, '%s_Workflow_Backend');
    }
}
