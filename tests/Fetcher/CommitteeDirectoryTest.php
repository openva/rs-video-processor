<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;

class CommitteeDirectoryTest extends TestCase
{
    public function testMatchesCommitteeNamesToIds(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE committees (
            id INTEGER PRIMARY KEY,
            name TEXT,
            shortname TEXT,
            chamber TEXT,
            parent_id INTEGER
        )');

                $committeeFixtures = [
            [
                'id' => 55,
                'name' => 'ABC/Gaming',
                'shortname' => 'abc',
                'chamber' => 'house',
                'parent_id' => 8
            ],
            [
                'id' => 73,
                'name' => 'Ad Hoc',
                'shortname' => 'ad-hoc',
                'chamber' => 'house',
                'parent_id' => 14
            ],
            [
                'id' => 26,
                'name' => 'Agriculture',
                'shortname' => 'agriculture',
                'chamber' => 'house',
                'parent_id' => 1
            ],
            [
                'id' => 1,
                'name' => 'Agriculture, Chesapeake and Natural Resources',
                'shortname' => 'agriculture',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 15,
                'name' => 'Agriculture, Conservation and Natural Resources',
                'shortname' => 'agriculture',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 96,
                'name' => 'Appointments Review',
                'shortname' => 'appointments',
                'chamber' => 'senate',
                'parent_id' => 24
            ],
            [
                'id' => 2,
                'name' => 'Appropriations',
                'shortname' => 'appropriations',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 92,
                'name' => 'Campaigns and Elections',
                'shortname' => 'elections',
                'chamber' => 'senate',
                'parent_id' => 22
            ],
            [
                'id' => 83,
                'name' => 'Capital Outlay',
                'shortname' => 'capital',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 29,
                'name' => 'Capital Outlay',
                'shortname' => 'capital',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 93,
                'name' => 'Certificate, Oath and Confirmation Review',
                'shortname' => 'certificate',
                'chamber' => 'senate',
                'parent_id' => 22
            ],
            [
                'id' => 91,
                'name' => 'Charters',
                'shortname' => 'charters',
                'chamber' => 'senate',
                'parent_id' => 21
            ],
            [
                'id' => 28,
                'name' => 'Chesapeake',
                'shortname' => 'chesapeake',
                'chamber' => 'house',
                'parent_id' => 1
            ],
            [
                'id' => 77,
                'name' => 'Civil',
                'shortname' => 'civil',
                'chamber' => 'senate',
                'parent_id' => 17
            ],
            [
                'id' => 43,
                'name' => 'Civil Law',
                'shortname' => 'civil',
                'chamber' => 'house',
                'parent_id' => 5
            ],
            [
                'id' => 84,
                'name' => 'Claims',
                'shortname' => 'claims',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 97,
                'name' => 'Commending, Memorial and Memorializing Resolution Review',
                'shortname' => 'commendments',
                'chamber' => 'senate',
                'parent_id' => 24
            ],
            [
                'id' => 106,
                'name' => 'Commerce and Energy',
                'shortname' => 'energy',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 16,
                'name' => 'Commerce and Labor',
                'shortname' => 'commerce',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 30,
                'name' => 'Commerce, Agriculture and Natural Resources',
                'shortname' => 'commerce',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 13,
                'name' => 'Communications, Technology and Innovation',
                'shortname' => 'science',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 103,
                'name' => 'Communications, Technology and Innovation Subcommittee #1',
                'shortname' => '1',
                'chamber' => 'house',
                'parent_id' => 13
            ],
            [
                'id' => 31,
                'name' => 'Compensation and Retirement',
                'shortname' => 'compensation',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 64,
                'name' => 'Constitutional',
                'shortname' => 'constitutional',
                'chamber' => 'house',
                'parent_id' => 11
            ],
            [
                'id' => 94,
                'name' => 'Constitutional Amendments, Reapportionment, Referenda',
                'shortname' => 'constitution',
                'chamber' => 'senate',
                'parent_id' => 22
            ],
            [
                'id' => 4,
                'name' => 'Counties, Cities and Towns',
                'shortname' => 'counties',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 40,
                'name' => 'Counties, Cities and Towns Subcommittee #1',
                'shortname' => '1',
                'chamber' => 'house',
                'parent_id' => 4
            ],
            [
                'id' => 41,
                'name' => 'Counties, Cities and Towns Subcommittee #2',
                'shortname' => '2',
                'chamber' => 'house',
                'parent_id' => 4
            ],
            [
                'id' => 5,
                'name' => 'Courts of Justice',
                'shortname' => 'courts',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 17,
                'name' => 'Courts of Justice',
                'shortname' => 'courts',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 78,
                'name' => 'Criminal',
                'shortname' => 'criminal',
                'chamber' => 'senate',
                'parent_id' => 17
            ],
            [
                'id' => 42,
                'name' => 'Criminal Law',
                'shortname' => 'criminal',
                'chamber' => 'house',
                'parent_id' => 5
            ],
            [
                'id' => 85,
                'name' => 'Economic Development/National Resources',
                'shortname' => 'development',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 86,
                'name' => 'Education',
                'shortname' => 'education',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 6,
                'name' => 'Education',
                'shortname' => 'education',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 18,
                'name' => 'Education and Health',
                'shortname' => 'education',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 65,
                'name' => 'Elections',
                'shortname' => 'elections',
                'chamber' => 'house',
                'parent_id' => 11
            ],
            [
                'id' => 32,
                'name' => 'Elementary and Secondary Education',
                'shortname' => 'primary-education',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 66,
                'name' => 'Finance',
                'shortname' => 'finance',
                'chamber' => 'house',
                'parent_id' => 11
            ],
            [
                'id' => 7,
                'name' => 'Finance',
                'shortname' => 'finance',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 19,
                'name' => 'Finance and Appropriations',
                'shortname' => 'finance',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 98,
                'name' => 'Financial Disclosure Review',
                'shortname' => 'finance',
                'chamber' => 'senate',
                'parent_id' => 24
            ],
            [
                'id' => 74,
                'name' => 'Financial Institutions and Insurance',
                'shortname' => 'finance',
                'chamber' => 'senate',
                'parent_id' => 16
            ],
            [
                'id' => 54,
                'name' => 'FOIA/Procurement',
                'shortname' => 'foia',
                'chamber' => 'house',
                'parent_id' => 8
            ],
            [
                'id' => 33,
                'name' => 'General Government and Technology',
                'shortname' => 'government',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 87,
                'name' => 'General Government/Technology',
                'shortname' => 'general',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 8,
                'name' => 'General Laws',
                'shortname' => 'general-laws',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 20,
                'name' => 'General Laws and Technology',
                'shortname' => 'general-laws',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 34,
                'name' => 'Health and Human Resources',
                'shortname' => 'health',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 88,
                'name' => 'Health and Human Resources',
                'shortname' => 'health',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 107,
                'name' => 'Health and Human Services',
                'shortname' => 'human-services',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 79,
                'name' => 'Health Care',
                'shortname' => 'health-care',
                'chamber' => 'senate',
                'parent_id' => 18
            ],
            [
                'id' => 101,
                'name' => 'Health Licensing',
                'shortname' => 'licensing',
                'chamber' => 'senate',
                'parent_id' => 18
            ],
            [
                'id' => 80,
                'name' => 'Health Professions',
                'shortname' => 'health-professions',
                'chamber' => 'senate',
                'parent_id' => 18
            ],
            [
                'id' => 57,
                'name' => 'Health, Welfare and Institutions',
                'shortname' => 'health',
                'chamber' => 'house',
                'parent_id' => 9
            ],
            [
                'id' => 35,
                'name' => 'Higher Education',
                'shortname' => 'higher-education',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 81,
                'name' => 'Higher Education',
                'shortname' => 'college',
                'chamber' => 'senate',
                'parent_id' => 18
            ],
            [
                'id' => 49,
                'name' => 'Higher Education',
                'shortname' => 'college',
                'chamber' => 'house',
                'parent_id' => 6
            ],
            [
                'id' => 53,
                'name' => 'Housing',
                'shortname' => 'housing',
                'chamber' => 'house',
                'parent_id' => 8
            ],
            [
                'id' => 59,
                'name' => 'Institutions',
                'shortname' => 'institutions',
                'chamber' => 'house',
                'parent_id' => 9
            ],
            [
                'id' => 95,
                'name' => 'Joint Reapportionment',
                'shortname' => 'reapportionment',
                'chamber' => 'senate',
                'parent_id' => 22
            ],
            [
                'id' => 67,
                'name' => 'Joint Rules',
                'shortname' => 'joint',
                'chamber' => 'house',
                'parent_id' => 12
            ],
            [
                'id' => 44,
                'name' => 'Judicial Panel',
                'shortname' => 'judicial',
                'chamber' => 'house',
                'parent_id' => 5
            ],
            [
                'id' => 3,
                'name' => 'Labor and Commerce',
                'shortname' => 'commerce',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 38,
                'name' => 'Labor and Commerce Subcommittee #1',
                'shortname' => '1',
                'chamber' => 'house',
                'parent_id' => 3
            ],
            [
                'id' => 39,
                'name' => 'Labor and Commerce Subcommittee #2',
                'shortname' => '2',
                'chamber' => 'house',
                'parent_id' => 3
            ],
            [
                'id' => 21,
                'name' => 'Local Government',
                'shortname' => 'local',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 45,
                'name' => 'Mental Health',
                'shortname' => 'mental-health',
                'chamber' => 'house',
                'parent_id' => 5
            ],
            [
                'id' => 104,
                'name' => 'Mental Health',
                'shortname' => 'mental-health',
                'chamber' => 'senate',
                'parent_id' => 17
            ],
            [
                'id' => 27,
                'name' => 'Natural Resources',
                'shortname' => 'natural-resources',
                'chamber' => 'house',
                'parent_id' => 1
            ],
            [
                'id' => 22,
                'name' => 'Privileges and Elections',
                'shortname' => 'pe',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 11,
                'name' => 'Privileges and Elections',
                'shortname' => 'pe',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 56,
                'name' => 'Professions/Occupations and Administrative Process',
                'shortname' => 'professions',
                'chamber' => 'house',
                'parent_id' => 8
            ],
            [
                'id' => 82,
                'name' => 'Public Education',
                'shortname' => 'public-education',
                'chamber' => 'senate',
                'parent_id' => 18
            ],
            [
                'id' => 10,
                'name' => 'Public Safety',
                'shortname' => 'public-safety',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 36,
                'name' => 'Public Safety',
                'shortname' => 'safety',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 89,
                'name' => 'Public Safety',
                'shortname' => 'safety',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 61,
                'name' => 'Public Safety Subcommittee #1',
                'shortname' => '1',
                'chamber' => 'house',
                'parent_id' => 10
            ],
            [
                'id' => 62,
                'name' => 'Public Safety Subcommittee #2',
                'shortname' => '2',
                'chamber' => 'house',
                'parent_id' => 10
            ],
            [
                'id' => 63,
                'name' => 'Public Safety Subcommittee #3',
                'shortname' => '3',
                'chamber' => 'house',
                'parent_id' => 10
            ],
            [
                'id' => 105,
                'name' => 'Redistricting',
                'shortname' => 'redistricting',
                'chamber' => 'senate',
                'parent_id' => 22
            ],
            [
                'id' => 23,
                'name' => 'Rehabilitation and Social Services',
                'shortname' => 'social-services',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 12,
                'name' => 'Rules',
                'shortname' => 'rules',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 24,
                'name' => 'Rules',
                'shortname' => 'rules',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 102,
                'name' => 'Special Public Smoking Legislation',
                'shortname' => 'smoking',
                'chamber' => 'senate',
                'parent_id' => 18
            ],
            [
                'id' => 99,
                'name' => 'Standards of Conduct',
                'shortname' => 'conduct',
                'chamber' => 'senate',
                'parent_id' => 24
            ],
            [
                'id' => 68,
                'name' => 'Standards of Conduct',
                'shortname' => 'conduct',
                'chamber' => 'house',
                'parent_id' => 12
            ],
            [
                'id' => 46,
                'name' => 'Standards of Quality',
                'shortname' => 'soq',
                'chamber' => 'house',
                'parent_id' => 6
            ],
            [
                'id' => 47,
                'name' => 'Students and Day Care',
                'shortname' => 'students',
                'chamber' => 'house',
                'parent_id' => 6
            ],
            [
                'id' => 100,
                'name' => 'Studies',
                'shortname' => 'studies',
                'chamber' => 'senate',
                'parent_id' => 24
            ],
            [
                'id' => 69,
                'name' => 'Studies',
                'shortname' => 'studies',
                'chamber' => 'house',
                'parent_id' => 12
            ],
            [
                'id' => 50,
                'name' => 'Subcomittee #1',
                'shortname' => '1',
                'chamber' => 'house',
                'parent_id' => 7
            ],
            [
                'id' => 51,
                'name' => 'Subcommittee #2',
                'shortname' => '2',
                'chamber' => 'house',
                'parent_id' => 7
            ],
            [
                'id' => 52,
                'name' => 'Subcommittee #3',
                'shortname' => '3',
                'chamber' => 'house',
                'parent_id' => 7
            ],
            [
                'id' => 48,
                'name' => 'Teachers and Administrative Action',
                'shortname' => 'teachers',
                'chamber' => 'house',
                'parent_id' => 6
            ],
            [
                'id' => 37,
                'name' => 'Transportation',
                'shortname' => 'transportation',
                'chamber' => 'house',
                'parent_id' => 2
            ],
            [
                'id' => 90,
                'name' => 'Transportation',
                'shortname' => 'transportation',
                'chamber' => 'senate',
                'parent_id' => 19
            ],
            [
                'id' => 25,
                'name' => 'Transportation',
                'shortname' => 'transportation',
                'chamber' => 'senate',
                'parent_id' => null
            ],
            [
                'id' => 14,
                'name' => 'Transportation',
                'shortname' => 'transportation',
                'chamber' => 'house',
                'parent_id' => null
            ],
            [
                'id' => 70,
                'name' => 'Transportation Subcommittee #1',
                'shortname' => '1',
                'chamber' => 'house',
                'parent_id' => 14
            ],
            [
                'id' => 71,
                'name' => 'Transportation Subcommittee #2',
                'shortname' => '2',
                'chamber' => 'house',
                'parent_id' => 14
            ],
            [
                'id' => 72,
                'name' => 'Transportation Subcommittee #3',
                'shortname' => '3',
                'chamber' => 'house',
                'parent_id' => 14
            ],
            [
                'id' => 75,
                'name' => 'Utilities',
                'shortname' => 'utilities',
                'chamber' => 'senate',
                'parent_id' => 16
            ],
            [
                'id' => 58,
                'name' => 'Welfare',
                'shortname' => 'welfare',
                'chamber' => 'house',
                'parent_id' => 9
            ],
            [
                'id' => 76,
                'name' => 'Workers\' Compensation, Unemployment Compensation and Labor',
                'shortname' => 'labor',
                'chamber' => 'senate',
                'parent_id' => 16
            ],
                ];

                $insert = $pdo->prepare('INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (:id, :name, :shortname, :chamber, :parent_id)');
                foreach ($committeeFixtures as $fixture) {
                    $insert->execute($fixture);
                }

                $directory = new CommitteeDirectory($pdo);

                $matchCases = [
                [
                'input' => 'Appropriations',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 2,
                ],
                [
                'input' => 'General Laws and Technology',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 20,
                ],
                [
                'input' => 'Education and Health: Public Education',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 82,
                ],
                [
                'input' => 'Education and Health',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 18,
                ],
                [
                'input' => 'Education and Health: Health',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 79,
                ],
                [
                'input' => 'Transportation',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 25,
                ],
                [
                'input' => 'SFAC: Health and Human Resources',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 88,
                ],
                [
                'input' => 'Privileges and Elections',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 22,
                ],
                [
                'input' => 'Local Government',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 21,
                ],
                [
                'input' => 'SFAC: Public Safety & Claims Subcommittee',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 84,
                ],
                [
                'input' => 'SFAC: Education Subcommittee',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 86,
                ],
                [
                'input' => 'Finance and Appropriations',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 19,
                ],
                [
                'input' => 'SFAC: Health and Human Resources Oversight Joint Subco',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 88,
                ],
                [
                'input' => 'Rehabilitation and Social Services',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 23,
                ],
                [
                'input' => 'Courts of Justice',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 17,
                ],
                [
                'input' => 'Commerce and Labor',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 16,
                ],
                [
                'input' => 'SFAC: Resources',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 85,
                ],
                [
                'input' => 'Health and Human Services',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 107,
                ],
                [
                'input' => 'Transportation',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 14,
                ],
                [
                'input' => 'General Laws',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 8,
                ],
                [
                'input' => 'CCT Subcommittee #2',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 41,
                ],
                [
                'input' => 'CCT Subcommittee #1',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 40,
                ],
                [
                'input' => 'Agriculture Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 26,
                ],
                [
                'input' => 'Education',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 6,
                ],
                [
                'input' => 'Chesapeake Subcomittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 28,
                ],
                [
                'input' => 'Agriculture, Chesapeake and Natural Resources',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 1,
                ],
                [
                'input' => 'Highway Safety and Policy Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => null,
                ],
                [
                'input' => 'Compensation and Retirement Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 76,
                ],
                [
                'input' => 'Transportation and Public Safety Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 37,
                ],
                [
                'input' => 'Civil Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 43,
                ],
                [
                'input' => 'Commerce, Agriculture and Natural Resources Sub',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 30,
                ],
                [
                'input' => 'Criminal Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 78,
                ],
                [
                'input' => 'Public Safety Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 36,
                ],
                [
                'input' => 'Labor and Commerce Subcommittee #2',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 39,
                ],
                [
                'input' => 'Natural Resources Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 27,
                ],
                [
                'input' => 'Housing-Consumer Protection Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => null,
                ],
                [
                'input' => 'Professions-Occupations and Administrative Process Subcommittee',
                'chamber' => 'house',
                'type' => 'subcommittee',
                'expected' => 56,
                ],
                [
                'input' => 'Labor and Commerce',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 3,
                ],
                [
                'input' => 'Appropriations',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 2,
                ],
                [
                'input' => 'Public Safety',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 10,
                ],
                [
                'input' => 'Counties Cities and Towns',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 4,
                ],
                [
                'input' => 'Privileges And Elections',
                'chamber' => 'house',
                'type' => 'committee',
                'expected' => 11,
                ],

                // --- Senate YouTube committee names (colon-prefix format, after title parsing) ---
                [
                'input' => 'Agriculture, Conservation and Natural Resources',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 15,
                ],
                [
                'input' => 'Rules',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 24,
                ],
                // "Education & Health" uses & which normalizeName converts to "and"
                [
                'input' => 'Education & Health',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => 18,
                ],
                [
                'input' => 'Education & Health: Higher Education',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 81,
                ],
                [
                'input' => 'Education & Health: Health Professions',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 80,
                ],
                [
                'input' => 'Education & Health: Public Education',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 82,
                ],
                [
                'input' => 'SFAC: Capital Outlay & Transportation Subcommittee',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 83,
                ],
                [
                'input' => 'SFAC: Economic Development & Natural Resources',
                'chamber' => 'senate',
                'type' => 'subcommittee',
                'expected' => 85,
                ],
                // Administrative Law Advisory Committee is not a standing Senate committee
                [
                'input' => 'Administrative Law Advisory Committee',
                'chamber' => 'senate',
                'type' => 'committee',
                'expected' => null,
                ],
                ];

                $failures = [];
                foreach ($matchCases as $case) {
                    $matched = $directory->matchId($case['input'], $case['chamber'], $case['type']);
                    $message = sprintf(
                        'Failed matching "%s" (%s/%s). Expected %s, got %s.',
                        $case['input'],
                        $case['chamber'],
                        $case['type'],
                        var_export($case['expected'], true),
                        var_export($matched, true)
                    );
                    try {
                        $this->assertSame($case['expected'], $matched, $message);
                    } catch (AssertionFailedError $error) {
                        $failures[] = $message;
                    }
                }
                if (!empty($failures)) {
                    $this->fail(implode("\n", $failures));
                }
    }
}
