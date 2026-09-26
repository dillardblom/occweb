# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
# @author Bernhard Posselt <dev@bernhard-posselt.com>
# @copyright Bernhard Posselt 2016

# Generic Makefile for building and packaging a Nextcloud app which uses npm and
# Composer.
#
# Dependencies:
# * make
# * which
# * curl: used if phpunit and composer are not installed to fetch them from the web
# * tar: for building the archive
# * npm: for building and testing everything JS
#
# If no composer.json is in the app root directory, the Composer step
# will be skipped. The same goes for the package.json which can be located in
# the app root or the js/ directory.
#
# The npm command by launches the npm build script:
#
#    npm run build
#
# The npm test command launches the npm test script:
#
#    npm run test
#
# The idea behind this is to be completely testing and build tool agnostic. All
# build tools and additional package managers should be installed locally in
# your project, since this won't pollute people's global namespace.
#
# The following npm scripts in your package.json install and update the bower
# and npm dependencies and use gulp as build system (notice how everything is
# run from the node_modules folder):
#
#    "scripts": {
#        "test": "node node_modules/gulp-cli/bin/gulp.js karma",
#        "prebuild": "npm install && node_modules/bower/bin/bower install && node_modules/bower/bin/bower update",
#        "build": "node node_modules/gulp-cli/bin/gulp.js"
#    },

app_name=$(shell xpath -q -e "//info/id/text()" appinfo/info.xml)
build_tools_directory=$(CURDIR)/build/tools
source_build_directory=$(CURDIR)/build/artifacts/source
source_package_name=$(source_build_directory)/$(app_name)
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
npm=$(shell which npm 2> /dev/null)
composer=$(shell which composer 2> /dev/null)

all: build

# Fetches the PHP and JS dependencies and compiles the JS. If no composer.json
# is present, the composer step is skipped, if no package.json or js/package.json
# is present, the npm step is skipped
.PHONY: build
build:
ifneq (,$(wildcard $(CURDIR)/composer.json))
	make composer
endif
ifneq (,$(wildcard $(CURDIR)/package.json))
	make npm
endif
ifneq (,$(wildcard $(CURDIR)/js/package.json))
	make npm
endif

# Installs and updates the composer dependencies. If composer is not installed
# a copy is fetched from the web
.PHONY: composer
composer:
ifeq (, $(composer))
	@echo "No composer command available, downloading a copy from the web"
	mkdir -p $(build_tools_directory)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(build_tools_directory)
	php $(build_tools_directory)/composer.phar install --prefer-dist
	php $(build_tools_directory)/composer.phar update --prefer-dist
else
	composer install --prefer-dist
	composer update --prefer-dist
endif

# Installs npm dependencies
.PHONY: npm
npm:
ifeq (,$(wildcard $(CURDIR)/package.json))
	cd js && $(npm) run build
else
	npm run build
endif

# Removes the appstore build
.PHONY: clean
clean:
	rm -rf ./build

# Same as clean but also removes dependencies installed by composer, bower and
# npm
.PHONY: distclean
distclean: clean
	rm -rf vendor
	rm -rf node_modules
	rm -rf js/vendor
	rm -rf js/node_modules

# Builds the source and appstore package
.PHONY: dist
dist:
	make source
	make appstore

# Both targets package via `git archive` rather than tarring the checkout
# directory directly: it only ever includes tracked files (so untracked/
# gitignored content - build/, notes/, any stray .env - can never leak into
# a release archive), it sets the correct top-level folder name via
# --prefix regardless of what this checkout is actually called (this repo
# is "occweb", the app id is "extended_occweb" - no symlink trick needed),
# and dev/CI-only files (tests/, Makefile, composer.*, phpunit*.xml,
# .travis.yml) are stripped via the `export-ignore` entries in
# .gitattributes instead of a long, easy-to-miss list of tar --exclude
# flags repeated per target. That also means source and appstore now
# produce the same archive; both targets are kept for compatibility with
# anything that invokes one of them by name.
.PHONY: source
source:
	rm -rf $(source_build_directory)
	mkdir -p $(source_build_directory)
	git archive --format=tar --prefix=$(app_name)/ HEAD | gzip > $(source_package_name).tar.gz

# Builds the source package for the app store, ignores php and js tests
.PHONY: appstore
appstore:
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)
	git archive --format=tar --prefix=$(app_name)/ HEAD | gzip > $(appstore_package_name).tar.gz

.PHONY: test
test: composer
	$(CURDIR)/vendor/phpunit/phpunit/phpunit -c phpunit.xml
	$(CURDIR)/vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml

.PHONY: sign
sign:
	@openssl dgst -sha512 -sign ~/.nextcloud/certificates/$(app_name).key $(appstore_package_name).tar.gz |openssl base64

VERSION := $(shell xpath -q -e "//info/version/text()" appinfo/info.xml)

.PHONY: show-version
show-version:
	@echo $(VERSION)

# Defaults to "origin" - correct for a plain clone of this repo, where
# "origin" is dillardblom/occweb. Override for a checkout where "origin"
# means something else (e.g. this maintainer's own machine, where the repo
# was originally forked from upstream and "origin" still points there):
#   make version RELEASE_REMOTE=fork
RELEASE_REMOTE ?= origin

# VERSION/app_name are read live from the working tree's appinfo/info.xml,
# but the archive this tags/packages/signs is git archive HEAD - i.e. the
# last COMMITTED state. Without this check, bumping <version> without
# committing first would tag and sign a package named/keyed after the new
# version while its actual contents are still the previous commit's.
.PHONY: check-clean-tree
check-clean-tree:
	@git diff --quiet HEAD -- || \
		(echo "Uncommitted changes to tracked files - commit before releasing (see 'git status')." >&2; exit 1)

.PHONY: version
version: check-clean-tree
	@echo "Creating version v$(VERSION)"
	@git tag v$(VERSION)
	@git push $(RELEASE_REMOTE) v$(VERSION)
	@make dist
	@echo "\nRelease Signature: \n"
	@make sign
