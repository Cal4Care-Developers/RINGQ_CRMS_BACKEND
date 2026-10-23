<?php
// Mobile app update popup (GET /api/appversion, ApiController::appversionAction).
// latest  = the build now in the App Store / Play Store; phones below it see
//           "Update available" (Update / Later).
// minimum = phones below it must update (no Later).
// 0 turns either off. Build numbers (14122), not version names (9.1.0).
// PHP rather than JSON because the sandbox deploy only ships PHP under app/.
return [
    "ios" => ["latest" => 0, "minimum" => 0, "version" => "", "url" => "https://apps.apple.com/app/id6468941160"],
    "android" => ["latest" => 0, "minimum" => 0, "version" => "", "url" => "https://play.google.com/store/apps/details?id=com.ringq.app"],
    "note" => "",
];
