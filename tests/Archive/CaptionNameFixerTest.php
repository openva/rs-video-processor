<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\CaptionNameFixer;

class CaptionNameFixerTest extends TestCase
{
    private const PATH = 'https://archive.org/download/rs-house-20260309-house-session/ia_video_3u9kpajsffjlaaeB9Lt.mp4';

    /** @param array<int,array<string,string>> $files */
    private static function item(array $files): array
    {
        return ['metadata' => ['identifier' => 'rs-house-20260309-house-session'], 'files' => $files];
    }

    private static function original(string $name): array
    {
        return ['name' => $name, 'source' => 'original'];
    }

    public function testFindsTheIdentifierInADownloadOrDetailsUrl(): void
    {
        $this->assertSame('rs-house-20260309-house-session', CaptionNameFixer::identifierFromPath(self::PATH));
        $this->assertSame('va-senate-20190128-18362', CaptionNameFixer::identifierFromPath('https://archive.org/details/va-senate-20190128-18362'));
        $this->assertNull(CaptionNameFixer::identifierFromPath('https://video.richmondsunlight.com/house/floor/x.mp4'));
    }

    /** The item from 2026-11-03, before its captions were renamed by hand. */
    public function testRenamesTheCaptionsAfterTheVideo(): void
    {
        $plan = CaptionNameFixer::plan(self::PATH, self::item([
            self::original('ia_video_3u9kpajsffjlaaeB9Lt.mp4'),
            self::original('ia_vtt_uq4j2q06so0u7xFQUuV.vtt'),
            ['name' => 'ia_vtt_uq4j2q06so0u7xFQUuV.asr.srt', 'source' => 'derivative', 'original' => 'ia_vtt_uq4j2q06so0u7xFQUuV.vtt'],
            self::original('rs-house-20260309-house-session_meta.xml'),
        ]));

        $this->assertSame('rename', $plan['action']);
        $this->assertSame('ia_vtt_uq4j2q06so0u7xFQUuV.vtt', $plan['from']);
        $this->assertSame('ia_video_3u9kpajsffjlaaeB9Lt.en.vtt', $plan['to']);
    }

    public function testSrtCaptionsKeepTheirFormat(): void
    {
        $plan = CaptionNameFixer::plan(self::PATH, self::item([
            self::original('ia_video_3u9kpajsffjlaaeB9Lt.mp4'),
            self::original('ia_srt_abc.srt'),
        ]));

        $this->assertSame('ia_video_3u9kpajsffjlaaeB9Lt.en.srt', $plan['to']);
    }

    /** Matching captions with the language code, or without it, as the archive's examples show. */
    public function testLeavesCaptionsThatAlreadyMatch(): void
    {
        foreach (['ia_video_3u9kpajsffjlaaeB9Lt.en.vtt', 'ia_video_3u9kpajsffjlaaeB9Lt.vtt'] as $name) {
            $plan = CaptionNameFixer::plan(self::PATH, self::item([
                self::original('ia_video_3u9kpajsffjlaaeB9Lt.mp4'),
                self::original('ia_vtt_uq4j2q06so0u7xFQUuV.vtt'),
                self::original($name),
            ]));
            $this->assertSame('ok', $plan['action'], $name);
        }
    }

    public function testNothingToDoWithoutCaptions(): void
    {
        $plan = CaptionNameFixer::plan(self::PATH, self::item([self::original('ia_video_3u9kpajsffjlaaeB9Lt.mp4')]));

        $this->assertSame('none', $plan['action']);
    }

    private static function at(string $name, int $mtime): array
    {
        return ['name' => $name, 'source' => 'original', 'mtime' => (string) $mtime];
    }

    /**
     * Identifiers once ended in the title, so two recordings of one day, or
     * retried uploads, could share an item. Each video went up in the same
     * ia upload as its captions, so they were stored seconds apart. These
     * are the files of rs-house-20260203-house-session.
     */
    public function testInAnItemWithSeveralVideosTheCaptionsStoredJustAfterTheVideoAreItsOwn(): void
    {
        $item = self::item([
            self::at('ia_video_8rukhb11dhb184yEmUv.mp4', 1770312152),
            self::at('ia_video_gthv9inm0kho8I9Gje3.mp4', 1770165498),
            self::at('ia_video_uqsu7a95rl49aTIXwOu.mp4', 1792217340),
            self::at('ia_video_v92e8ue8ie97afKsjGx.mp4', 1770233212),
            self::at('ia_vtt_3qqe4n152tj17SCxXRu.vtt', 1792217343),
            self::at('ia_vtt_clu9ccb9bj7jbTPedhQ.vtt', 1770233213),
            self::at('ia_vtt_jv470eco3j5d75cCMrQ.vtt', 1770312155),
            self::at('ia_vtt_v8nbgqalh8f01cIa7xM.vtt', 1770165513),
        ]);
        $path = 'https://archive.org/download/rs-house-20260203-house-session/';

        $plan = CaptionNameFixer::plan($path . 'ia_video_v92e8ue8ie97afKsjGx.mp4', $item);
        $this->assertSame(['ia_vtt_clu9ccb9bj7jbTPedhQ.vtt', 'ia_video_v92e8ue8ie97afKsjGx.en.vtt'], [$plan['from'], $plan['to']]);

        $plan = CaptionNameFixer::plan($path . 'ia_video_uqsu7a95rl49aTIXwOu.mp4', $item);
        $this->assertSame(['ia_vtt_3qqe4n152tj17SCxXRu.vtt', 'ia_video_uqsu7a95rl49aTIXwOu.en.vtt'], [$plan['from'], $plan['to']]);
    }

    /** One video and several caption files: only the ones uploaded with it are its own. */
    public function testRetriedCaptionsAreToldApartByTime(): void
    {
        $item = self::item([
            self::at('ia_video_3u9kpajsffjlaaeB9Lt.mp4', 1000),
            self::at('ia_vtt_old.vtt', 500),
            self::at('ia_vtt_new.vtt', 1004),
        ]);

        $this->assertSame('ia_vtt_new.vtt', CaptionNameFixer::plan(self::PATH, $item)['from']);
    }

    public function testSkipsWhenNoCaptionsWereStoredJustAfterTheVideo(): void
    {
        $item = self::item([
            self::at('ia_video_3u9kpajsffjlaaeB9Lt.mp4', 1000),
            self::at('ia_video_other.mp4', 5000),
            self::at('ia_vtt_one.vtt', 5003),
            self::at('ia_vtt_two.vtt', 9000),
        ]);

        $plan = CaptionNameFixer::plan(self::PATH, $item);
        $this->assertSame('skip', $plan['action']);
        $this->assertStringContainsString('2 caption files', $plan['reason']);
    }

    public function testSkipsWhenTwoCaptionFilesWereStoredJustAfterTheVideo(): void
    {
        $item = self::item([
            self::at('ia_video_3u9kpajsffjlaaeB9Lt.mp4', 1000),
            self::at('ia_vtt_one.vtt', 1003),
            self::at('ia_vtt_two.vtt', 1005),
        ]);

        $this->assertSame('skip', CaptionNameFixer::plan(self::PATH, $item)['action']);
    }

    /** Captions already named after another video in the item belong to that one. */
    public function testCaptionsNamedAfterAnotherVideoAreLeftToIt(): void
    {
        $item = self::item([
            self::at('ia_video_3u9kpajsffjlaaeB9Lt.mp4', 1000),
            self::at('ia_video_other.mp4', 1000),
            self::at('ia_video_other.en.vtt', 1003),
            self::at('ia_vtt_mine.vtt', 1004),
        ]);

        $this->assertSame('ia_vtt_mine.vtt', CaptionNameFixer::plan(self::PATH, $item)['from']);
    }

    public function testSkipsWhenTheVideoIsNotInTheItem(): void
    {
        $plan = CaptionNameFixer::plan(self::PATH, self::item([
            self::original('something_else.mp4'),
            self::original('ia_vtt_one.vtt'),
        ]));

        $this->assertSame('skip', $plan['action']);
    }

    /** An old details URL names no file, so the item's only original MP4 is the video. */
    public function testADetailsUrlUsesTheItemsOnlyVideo(): void
    {
        $item = self::item([self::original('ia_video_abc.mp4'), self::original('ia_vtt_one.vtt')]);

        $plan = CaptionNameFixer::plan('https://archive.org/details/rs-house-20260309-house-session', $item);
        $this->assertSame('ia_video_abc.en.vtt', $plan['to']);

        $item['files'][] = self::original('ia_video_def.mp4');
        $this->assertSame('skip', CaptionNameFixer::plan('https://archive.org/details/rs-house-20260309-house-session', $item)['action']);
    }

    public function testFileNamesInTheUrlAreDecoded(): void
    {
        $plan = CaptionNameFixer::plan(
            'https://archive.org/download/rs-house-20260309-house-session/House%20Session.mp4',
            self::item([self::original('House Session.mp4'), self::original('ia_vtt_one.vtt')])
        );

        $this->assertSame('House Session.en.vtt', $plan['to']);
    }
}
