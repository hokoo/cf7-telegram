setup.all:
	bash ./install/setup.sh

setup.env:
	bash ./install/setup-env.sh

docker.up:
	docker-compose -p cf7tg up -d

docker.down:
	docker-compose -p cf7tg down

docker.build.php:
	docker-compose -p cf7tg up -d --build php

git.wpc:
	bash ./install/gitwpc.sh

clear.all:
	bash ./install/clear.sh

npm.build:
	docker-compose -p cf7tg exec node bash -c "cd ./cf7-telegram/react && npm run dev-build"

php.connect:
	docker-compose -p cf7tg exec php bash

php.connect.root:
	docker-compose -p cf7tg exec --user=root php bash

node.connect:
	docker-compose -p cf7tg exec node bash

node.connect.root:
	docker-compose -p cf7tg exec --user=root node bash

php.log:
	docker-compose -p cf7tg exec php sh -c 'tail -n 50 -f /var/log/php/error.log'

i18n.make.json:
ifeq ($(origin LOCALE), undefined)
	@echo "❌ Specify the LOCALE variable. Example: make i18n.make.json LOCALE=ru_RU"
else
	@docker-compose -p cf7tg exec php sh -c '\
		cd ./cf7-telegram && \
		echo "🔄 Updating localization files for locale: $(LOCALE)"; \
		wp i18n make-json ./languages/cf7-telegram-$(LOCALE).po --no-purge && \
		TARGET="./languages/cf7-telegram-$(LOCALE)-cf7-telegram-admin.json"; \
		if [ -f "$$TARGET" ]; then \
			echo "🗑 Removing previous version: $$TARGET"; \
			rm "$$TARGET"; \
		fi && \
		JSON_FILE=$$(find ./languages -type f -name "cf7-telegram-$(LOCALE)-*.json" | grep -v "cf7-telegram-admin.json" | head -n 1); \
		if [ -n "$$JSON_FILE" ]; then \
			mv "$$JSON_FILE" "$$TARGET"; \
			echo "✅ Renaming: $$JSON_FILE → $$TARGET"; \
		else \
			echo "❗ Source JSON file not found: cf7-telegram-$(LOCALE)-*.json"; \
		fi'
endif

