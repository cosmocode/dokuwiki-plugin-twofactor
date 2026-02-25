<?php
$lang["optinout"] = 'Configure how users can chose to use two-factor authentication: Opt-In, Opt-Out, or Mandatory.';
$lang["optinout_o_optin"] = 'Opt-In';
$lang["optinout_o_optout"] = 'Opt-Out';
$lang["optinout_o_mandatory"] = 'Mandatory';

$lang["useinternaluid"] = "If this option is off, DokuWiki will not require re-authentication in case of user IP change.";
$lang["trustedIPs"] = "A regular expression (no delimiters) to match against the user's IP address. If the user is coming from one of these IPs, the 2FA will be skipped.";
$lang["allowTokenAuth"] = "Allow access to special ressources (like plugin endpoints) without 2FA when using token auth. Access to the API is always allowed without 2FA when using token auth, regardless of this setting.";
