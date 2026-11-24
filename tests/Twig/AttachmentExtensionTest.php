<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Attachments\PartAttachment;
use App\Twig\AttachmentExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AttachmentExtensionTest extends KernelTestCase
{
    public static function linkProvider(): \Iterator
    {
        yield 'a file' => ['https://example.com/docs/datasheet.pdf', 'datasheet.pdf'];
        yield 'encoded, with query and fragment' => ['https://example.com/media/WL-SMCW%20Series%200603_web%28640x640%29.jpg?w=2#top', 'WL-SMCW Series 0603_web(640x640).jpg'];
        yield 'no path' => ['https://example.com', 'Named'];
        yield 'a folder' => ['https://example.com/products/', 'products'];
    }

    #[DataProvider('linkProvider')]
    public function testTheFileNameOfALinkIsTheLastPartOfItsAddress(string $url, string $expected): void
    {
        self::bootKernel();
        $extension = self::getContainer()->get(AttachmentExtension::class);
        $attachment = new PartAttachment();
        $attachment->setName('Named');
        $attachment->setExternalPath($url);

        $this->assertSame($expected, $extension->attachmentFileName($attachment));
    }

    public function testAnAttachmentWithoutFileOrLinkIsNamedByItsName(): void
    {
        self::bootKernel();
        $extension = self::getContainer()->get(AttachmentExtension::class);
        $attachment = new PartAttachment();
        $attachment->setName('Only a name');

        $this->assertSame('Only a name', $extension->attachmentFileName($attachment));
    }
}
