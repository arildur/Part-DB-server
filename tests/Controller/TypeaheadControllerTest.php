<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Tests\Controller;

use App\Settings\MiscSettings\IpnSuggestSettings;
use App\Entity\Parts\Category;
use App\Entity\Parts\Footprint;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartKind;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests the TypeaheadController JSON endpoints that back autocomplete widgets in the UI.
 */
#[Group('DB')]
#[Group('slow')]
final class TypeaheadControllerTest extends WebTestCase
{
    public static function endpointProvider(): \Generator
    {
        yield 'tags search'                => ['/en/typeahead/tags/search/test'];
        yield 'parameters part search'     => ['/en/typeahead/parameters/part/search/voltage'];
        yield 'parameters category search' => ['/en/typeahead/parameters/category/search/NPN'];
        yield 'builtin resources'          => ['/en/typeahead/builtInResources/search?query=DIP'];
        yield 'parts search'               => ['/en/typeahead/parts/search/res'];
    }

    public static function partsReadEndpointProvider(): \Generator
    {
        // These require @parts.read — noread user must be denied
        yield 'tags search'            => ['/en/typeahead/tags/search/test'];
        yield 'parameters part search' => ['/en/typeahead/parameters/part/search/voltage'];
        yield 'parts search'           => ['/en/typeahead/parts/search/res'];
    }

    private function loginClient(string $username): KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['name' => $username]);
        if ($user === null) {
            $this->markTestSkipped("Fixture user '$username' not found.");
        }
        $client->loginUser($user);
        return $client;
    }

    // -----------------------------------------------------------------------
    // Response format
    // -----------------------------------------------------------------------

    #[DataProvider('endpointProvider')]
    public function testEndpointReturnsSuccessfulJsonForAdmin(string $url): void
    {
        $client = $this->loginClient('admin');
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
    }

    #[DataProvider('endpointProvider')]
    public function testEndpointReturnsJsonArray(string $url): void
    {
        $client = $this->loginClient('admin');
        $client->request('GET', $url);

        $decoded = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded, "Response from $url should be a JSON array");
    }

    // -----------------------------------------------------------------------
    // Tags search: result structure
    // -----------------------------------------------------------------------

    public function testTagsSearchReturnsStrings(): void
    {
        $client = $this->loginClient('admin');
        $client->request('GET', '/en/typeahead/tags/search/a');

        $tags = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($tags);
        foreach ($tags as $tag) {
            $this->assertIsString($tag, 'Each tag entry should be a plain string');
        }
    }

    // -----------------------------------------------------------------------
    // Parts search: result structure
    // -----------------------------------------------------------------------

    public function testPartsSearchReturnsArrayWithExpectedKeys(): void
    {
        $client = $this->loginClient('admin');
        $client->request('GET', '/en/typeahead/parts/search/test');

        $parts = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($parts);
        // Each result must have at least id and name
        foreach ($parts as $part) {
            $this->assertArrayHasKey('id', $part);
            $this->assertArrayHasKey('name', $part);
        }
    }

    public function testPartsSearchSortedByTopCategoryReturnsRootCategory(): void
    {
        $client = $this->loginClient('admin');
        $client->request('GET', '/en/typeahead/parts/search/Part?name=1&sort=top_category');

        $parts = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotEmpty($parts);
        // All fixture parts are in the root category "Node 1"
        foreach ($parts as $part) {
            $this->assertSame('Node 1', $part['sort_value']);
        }
    }

    // -----------------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------------

    #[DataProvider('endpointProvider')]
    public function testUnauthenticatedCanAccessTypeahead(string $url): void
    {
        // Anonymous user (readonly group) has @parts.read, so these endpoints return 200.
        $client = static::createClient();
        $client->request('GET', $url);
        $this->assertResponseIsSuccessful();
    }

    #[DataProvider('partsReadEndpointProvider')]
    public function testNoreadUserIsDenied(string $url): void
    {
        $client = $this->loginClient('noread');
        $client->followRedirects(false);
        $client->request('GET', $url);

        $response = $client->getResponse();
        $this->assertTrue(
            $response->getStatusCode() === 403 || $response->isRedirect(),
            "Expected 403 or redirect for noread user on $url, got " . $response->getStatusCode()
        );
    }

    #[DataProvider('endpointProvider')]
    public function testEditorUserCanAccess(string $url): void
    {
        $client = $this->loginClient('user');
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    /** @return list<array<string, mixed>> */
    private function pickerResults(string $query, array $parameters = []): array
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($em->getRepository(User::class)->findOneBy(['name' => 'admin']));

        $category = new Category();
        $category->setName('Pickcategory');
        $em->persist($category);
        $footprint = new Footprint();
        $footprint->setName('Pickfootprint');
        $em->persist($footprint);
        foreach (['manufacturing', 'meta'] as $kind) {
            $part = (new Part())->setKind(PartKind::from($kind));
            $part->setName('Picker '.$kind);
            $part->setDescription('Pickdescription');
            $part->setCategory($category);
            $part->setFootprint($footprint);
            $em->persist($part);
        }
        $em->flush();

        $client->request('GET', '/en/typeahead/parts/search/'.$query, $parameters);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testPickerResultsMarkAMetaPart(): void
    {
        $results = $this->pickerResults('Picker');
        $labels = array_column($results, 'kind_label', 'name');

        $this->assertSame('Meta part', $labels['Picker meta']);
        $this->assertSame('', $labels['Picker manufacturing']);
    }

    /** @return iterable<string, array{string}> */
    public static function searchTerms(): iterable
    {
        yield 'name' => ['Picker'];
        yield 'description' => ['Pickdescription'];
        yield 'category' => ['Pickcategory'];
        yield 'footprint' => ['Pickfootprint'];
    }

    #[DataProvider('searchTerms')]
    public function testThePickerRestrictionRemovesOnlyTheOtherKindOnEverySearchField(string $term): void
    {
        $results = $this->pickerResults($term, ['kind' => 'manufacturing']);

        $this->assertSame(['Picker manufacturing'], array_column($results, 'name'), 'The restriction must not switch off the search fields');
        $this->assertSame('manufacturing', $results[0]['kind']);
    }

    #[DataProvider('searchTerms')]
    public function testWithoutRestrictionEverySearchFieldFindsBothKinds(string $term): void
    {
        $this->assertCount(2, $this->pickerResults($term));
    }

    public function testThePickerRestrictionWorksTogetherWithSearchOptions(): void
    {
        $names = array_column($this->pickerResults('Picker', ['kind' => 'meta', 'name' => '1']), 'name');

        $this->assertSame(['Picker meta'], $names);
    }

    public function testIpnSuggestionsFollowTheKindOfANewPart(): void
    {
        $client = $this->loginClient('admin');
        $client->disableReboot();
        $settings = static::getContainer()->get(IpnSuggestSettings::class);

        $titles = function (string $query) use ($client): array {
            $client->request('GET', '/en/typeahead/parts/ipn-suggestions?categoryId=0'.$query);
            self::assertResponseIsSuccessful();

            return array_column(json_decode((string) $client->getResponse()->getContent(), true)['commonPrefixes'], 'title');
        };

        $settings->globalPrefix = 'ALL-';
        $this->assertContains('ALL-', $titles(''), 'With no meta prefix, both kinds use the prefix of today');
        $this->assertContains('ALL-', $titles('&kind=meta'));

        $settings->metaPrefix = 'META-';
        $this->assertContains('ALL-', $titles(''));
        $this->assertContains('ALL-', $titles('&kind=manufacturing'));
        $this->assertContains('META-', $titles('&kind=meta'));
        $this->assertNotContains('ALL-', $titles('&kind=meta'));
        $this->assertContains('ALL-', $titles('&kind=nonsense'), 'An unknown kind is a manufacturing part, as everywhere');

        //The kind of a saved part is its own, whatever the form says
        $this->assertContains('ALL-', $titles('&partId=1&kind=meta'));
    }
}
