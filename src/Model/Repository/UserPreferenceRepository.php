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

namespace Eventum\Model\Repository;

use Doctrine\ORM\EntityRepository;
use Eventum\Model\Entity\UserPreference;
use Eventum\Model\Entity\UserProjectPreference;
use Eventum\Model\Repository\Traits\FindByIdTrait;

/**
 * @method UserPreference findById(int $usr_id)
 */
class UserPreferenceRepository extends EntityRepository
{
    use FindByIdTrait;

    public function persistAndFlush(UserPreference $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush($entity);
    }

    public function findOrCreate(int $id): UserPreference
    {
        $cf = $this->find($id);
        if (!$cf) {
            $cf = new UserPreference();
        }

        return $cf;
    }

    public function updateProjectPreference(int $usr_id, array $projects): void
    {
        $em = $this->getEntityManager();
        $upr = $this->findOrCreate($usr_id);

        foreach ($projects as $prj_id => $data) {
            $upp = $upr->getProjectById($prj_id);
            if (!$upp) {
                $upp = new UserProjectPreference();
                $upp->setUserPreference($upr);
                $upp->setProjectId($prj_id);
            }

            $upp
                ->setReceiveNewIssueEmail($data['receive_new_issue_email'])
                ->setReceiveAssignedEmail($data['receive_assigned_email'])
                ->setReceiveCopyOfOwnAction($data['receive_copy_of_own_action']);
            $em->persist($upp);
        }

        $em->flush();
    }
}
