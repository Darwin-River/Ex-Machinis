#include <stdio.h>
#include <mysql.h>

int main() {
    printf("MySQL Client Version %lu\n", mysql_get_client_version());

    return 0;
}

