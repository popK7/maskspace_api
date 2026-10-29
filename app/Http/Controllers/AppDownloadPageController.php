<?php

namespace App\Http\Controllers;

use App\Services\Releases\AppReleaseStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppDownloadPageController extends Controller
{
    public function __construct(private readonly AppReleaseStore $releases) {}

    public function __invoke(): View
    {
        $testFlightUrl = config('app_downloads.ios_testflight_url');

        return view('downloads.index', [
            'androidAvailable' => $this->releases->exists(AppReleaseStore::ANDROID),
            'iosAvailable' => filled($testFlightUrl) || $this->releases->exists(AppReleaseStore::IOS),
            'iosUsesTestFlight' => filled($testFlightUrl),
        ]);
    }

    public function ios(): RedirectResponse|StreamedResponse
    {
        $testFlightUrl = config('app_downloads.ios_testflight_url');

        if (filled($testFlightUrl)) {
            return redirect()->away($testFlightUrl);
        }

        abort_unless($this->releases->exists(AppReleaseStore::IOS), 404);

        return $this->releases->download(AppReleaseStore::IOS);
    }
}
