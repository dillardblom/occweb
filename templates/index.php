<?php
// Nextcloud's global jQuery has been deprecated since NC19 and is no longer
// reliably present on NC34+ (see e.g. serverinfo needing to bundle its own) -
// term.js (jquery.terminal) and index.js both require jQuery to be loaded
// first, so ship and load our own copy rather than relying on core.
script('occweb', 'jquery');
script('occweb', 'index');
script('occweb', 'term');
script('occweb', 'unix_formatting');
style('occweb', 'style');
?>

<div id="app" class="full-width">
<!--	<div id="app-navigation">-->
<!--		--><?php //print_unescaped($this->inc('navigation/index')); ?>
<!--		--><?php //print_unescaped($this->inc('settings/index')); ?>
<!--	</div>-->

	<div id="app-content" class="full-width">
	</div>
</div>

