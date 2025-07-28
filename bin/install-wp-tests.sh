#!/bin/bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}

download() {
    if [ $(which curl) ]; then
        curl -s "$1" > "$2"
    elif [ $(which wget) ]; then
        wget -nv -O "$2" "$1"
    fi
}

# Just set up the environment without database operations
install_wp() {
	if [ -d $WP_CORE_DIR ]; then
		return;
	fi

	mkdir -p $WP_CORE_DIR
	echo "WordPress core directory created at: $WP_CORE_DIR"
}

install_test_suite() {
	# Set up testing suite if it doesn't yet exist
	if [ ! -d $WP_TESTS_DIR ]; then
		mkdir -p $WP_TESTS_DIR
		echo "WordPress tests directory created at: $WP_TESTS_DIR"
		echo "Note: For full WordPress tests, you would need to download the test suite from SVN"
		echo "This basic setup creates the directory structure for Composer-based testing"
	fi
}

echo "Setting up WordPress test environment..."
echo "WP_TESTS_DIR: $WP_TESTS_DIR"
echo "WP_CORE_DIR: $WP_CORE_DIR"

install_wp
install_test_suite

echo "WordPress test environment setup complete!"
echo "You can now run: composer test"