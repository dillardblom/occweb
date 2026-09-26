<?php
// Nextcloud's global jQuery has been deprecated since NC19 and is no longer
// reliably present on NC34+ (see e.g. serverinfo needing to bundle its own) -
// term.js (jquery.terminal) and index.js both require jQuery to be loaded
// first, so ship and load our own copy where core no longer provides one.
//
// Only do this on NC34+: on NC32/33 core still exposes a working jQuery, but
// via a read-only `window.$` getter - loading our vendored copy there too
// makes its UMD wrapper throw trying to reassign `window.$` (confirmed via
// testbak/, see the README compat matrix). Harmless in practice (this app
// attaches via window.jQuery, not window.$) but a real, avoidable console
// exception, so skip the extra load where core's own jQuery already works.
if (\OCP\Server::get(\OCP\ServerVersion::class)->getMajorVersion() >= 34) {
    script('extended_occweb', 'jquery');
}
script('extended_occweb', 'index');
script('extended_occweb', 'term');
script('extended_occweb', 'unix_formatting');
style('extended_occweb', 'style');
?>

<div id="app" class="full-width">
<!--	<div id="app-navigation">-->
<!--		--><?php //print_unescaped($this->inc('navigation/index')); ?>
<!--		--><?php //print_unescaped($this->inc('settings/index')); ?>
<!--	</div>-->

	<div id="app-content" class="full-width">
	</div>
</div>

