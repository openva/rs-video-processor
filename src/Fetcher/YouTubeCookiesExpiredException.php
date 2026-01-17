<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

/**
 * Exception thrown when YouTube cookies have expired and need to be refreshed.
 *
 * This indicates that yt-dlp received a "Sign in to confirm you're not a bot" error,
 * which means the cookies file needs to be updated with fresh cookies from a browser.
 */
class YouTubeCookiesExpiredException extends \RuntimeException
{
}
