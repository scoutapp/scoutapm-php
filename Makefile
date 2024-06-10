.PHONY: *

PHP_VERSION=7.4
PHP_PATH=/usr/bin/env php$(PHP_VERSION)
COMPOSER_PATH=/usr/local/bin/composer
OPTS=

default: unit cs static-analysis ## all the things

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

unit: ## run unit tests
	$(PHP_PATH) vendor/bin/phpunit $(OPTS)

cs: ## verify code style rules
	$(PHP_PATH) vendor/bin/phpcs $(OPTS)

cs-fix: ## auto fix code style rules
	$(PHP_PATH) vendor/bin/phpcbf $(OPTS)

static-analysis: ## verify that no new static analysis issues were introduced
	$(PHP_PATH) vendor/bin/psalm --threads=1 $(OPTS)

coverage: ## generate code coverage reports
	$(PHP_PATH) vendor/bin/phpunit --testsuite unit --coverage-html build/coverage-html --coverage-text $(OPTS)

deps-install: ## Install the currently-locked set of dependencies
	git restore composer.lock
	rm -Rf vendor
	$(PHP_PATH) $(COMPOSER_PATH) install

deps-lowest: ## Update deps to lowest
	$(PHP_PATH) $(COMPOSER_PATH) update --prefer-lowest --prefer-dist --no-interaction
	rm -Rf vendor
	$(PHP_PATH) $(COMPOSER_PATH) install

deps-highest: ## Update deps to highest
	$(PHP_PATH) $(COMPOSER_PATH) update --prefer-dist --no-interaction
	rm -Rf vendor
	$(PHP_PATH) $(COMPOSER_PATH) install

update-static-analysis-baseline: ## bump static analysis baseline issues, reducing set of allowed failures
	$(PHP_PATH) vendor/bin/psalm --update-baseline --threads=1

reset-static-analysis-baseline: ## reset static analysis baseline issues to current HEAD
	$(PHP_PATH) vendor/bin/psalm --set-baseline=known-issues.xml --threads=1
