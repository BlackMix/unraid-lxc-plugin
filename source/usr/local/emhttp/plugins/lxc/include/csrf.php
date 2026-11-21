<?php
/**
 * Publishes the webGui CSRF token to the plugin JavaScript layer.
 *
 * lxc.js installs a jQuery ajaxPrefilter that reads window.LXCV2_CSRF and adds
 * the X-CSRF-Token header to every /plugins/lxc/ POST. If a page loads lxc.js
 * without including this file, the prefilter bails out early and ajax.php
 * rejects the request with "Invalid CSRF token. Reload the page and try again."
 *
 * Every page that loads lxc.js must require this file BEFORE the script tag.
 *
 * The token is read from /var/local/emhttp/var.ini first — the same
 * authoritative source ajax.php validates against — with $var as a fallback on
 * releases that do not expose it to pages. When no token is published, ajax.php
 * skips validation (older Unraid) and this emits an empty token; both sides
 * stay symmetric, so no POST is blocked in that case.
 */
$lxv2VarIni = @parse_ini_file('/var/local/emhttp/var.ini');
$lxv2CsrfToken = is_array($lxv2VarIni) ? (string) ($lxv2VarIni['csrf_token'] ?? '') : '';
if ($lxv2CsrfToken === '') {
    $lxv2CsrfToken = (string) ($var['csrf_token'] ?? '');
}
?>
<script>
  window.LXCV2_CSRF = <?= json_encode(
      $lxv2CsrfToken,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;
</script>
