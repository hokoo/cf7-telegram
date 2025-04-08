setup.all:
	bash ./install/setup.sh

setup.env:
	bash ./install/setup-env.sh

docker.up:
	docker-compose -p cf7tg up -d

docker.down:
	docker-compose -p cf7tg down

git.wpc:
	bash ./install/gitwpc.sh

clear.all:
	bash ./install/clear.sh

npm.build:
	docker-compose -p cf7tg exec node bash -c "cd ./cf7-telegram/react && npm run build"

php.connect:
	docker-compose -p cf7tg exec php bash

php.connect.root:
	docker-compose -p cf7tg exec --user=root php bash

node.connect:
	docker-compose -p cf7tg exec node bash

node.connect.root:
	docker-compose -p cf7tg exec --user=root node bash
