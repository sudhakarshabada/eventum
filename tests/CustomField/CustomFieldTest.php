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

namespace Eventum\Test\CustomField;

use Eventum\Db\Doctrine;
use Eventum\Model\Entity\CustomField;
use Eventum\Test\TestCase;

class CustomFieldTest extends TestCase
{
    public function testGetCustomField(): void
    {
        $repo = Doctrine::getCustomFieldRepository();
        /** @var CustomField $cf */
        $cf = $repo->findById(2);
        dump($cf !== null);
    }

    /**
     * @see Custom_Field::getListByIssue
     */
    public function testGetListByIssue(): void
    {
        $prj_id = 1;
        $iss_id = 20;
        $repo = Doctrine::getCustomFieldRepository();
        $list = $repo->getListByIssue($prj_id, $iss_id);
        dump(count($list));
    }
}
