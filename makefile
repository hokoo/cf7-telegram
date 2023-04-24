setup.all:
	bash ./install/setup.sh

setup.env:
	bash ./install/setup-env.sh

git.wpc:
	bash ./install/gitwpc.sh

sync:
	bash ./install/sync.sh $(filter-out $@,$(MAKECMDGOALS))

clear.all:
	bash ./install/clear.sh

php.connect:
	docker-compose -p cf7tg exec php bash

php.connect.root:
	docker-compose -p cf7tg exec --user=root php bash

node.connect:
	docker-compose -p cf7tg exec node bash

node.connect.root:
	docker-compose -p cf7tg exec --user=root node bash
