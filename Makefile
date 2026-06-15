.PHONY: trunk pre-commit install-hooks

STAGED_PHP = $(shell git diff --diff-filter=AM --staged --name-only | grep '\.php$$')

trunk:
	git checkout main && git pull

pre-commit:
	@if [ -n "$(STAGED_PHP)" ]; then \
		echo "$(STAGED_PHP)" | xargs vendor/bin/phpcs; \
	fi

install-hooks:
	@echo '#!/usr/bin/env bash' > .git/hooks/pre-commit
	@echo 'make pre-commit' >> .git/hooks/pre-commit
	@chmod +x .git/hooks/pre-commit
	@echo "Installed .git/hooks/pre-commit"
