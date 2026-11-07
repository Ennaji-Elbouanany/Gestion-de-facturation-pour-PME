"""Dump/restore rehearsal on a local mysql:8.0 test container only.
Uses synthetic financial rows. Never accepts a remote MySQL hostname.
"""
import hashlib
import json
import os
import re
import subprocess
import sys


def run(args, data=None):
    result = subprocess.run(args, input=data, capture_output=True)
    if result.returncode:
        raise RuntimeError('Local backup/restore operation failed; provider output suppressed.')
    return result.stdout


def main(container):
    config = json.loads(run(['docker', 'inspect', container]))[0]
    require = lambda condition: condition or (_ for _ in ()).throw(RuntimeError('Isolated test target required.'))
    require(config['Id'] == container and config['Config']['Image'] == 'mysql:8.0')
    require(config['Name'].startswith('/billing-'))
    bindings = config['HostConfig']['PortBindings']['3306/tcp']
    require(all(binding['HostIp'] == '127.0.0.1' for binding in bindings))
    source, target = 'billing_cicd_test', 'billing_restore_test'
    password = os.environ['LOCAL_MYSQL_TEST_PASSWORD']
    command = ['docker', 'exec', '-i', '-e', 'MYSQL_PWD=' + password, container]

    def sql(query, database=None):
        return run(command + ['mysql', '-uroot', '--host=127.0.0.1', '--batch', '--raw', '--skip-column-names']
                   + ([database] if database else []), query.encode())

    require(sql('SELECT COUNT(*) FROM invoices;', source).strip() == b'0')
    fixture = """START TRANSACTION;
INSERT INTO companies (id,name) VALUES (9001,'Restore fixture');
INSERT INTO clients (id,company_id,name,deleted_at) VALUES (9001,9001,'Archived fixture','2026-11-07');
INSERT INTO invoices (id,company_id,client_id,invoice_number,invoice_date,subtotal,total_amount,status,document_status)
VALUES (9001,9001,9001,'RESTORE-ONLY','2026-11-07',100.00,100.00,'partial','issued');
INSERT INTO invoice_items (invoice_id,description,quantity,unit_price,subtotal,total) VALUES (9001,'Fixture',1,100.00,100.00,100.00);
INSERT INTO payments (id,invoice_id,amount,payment_date,payment_method,version,idempotency_key,request_hash)
VALUES (9001,9001,25.00,'2026-11-07','cash',2,'cf03c798-2506-42f0-b33f-a27e444e2f6a',REPEAT('a',64));
INSERT INTO payment_revisions (payment_id,actor_name,reason,`before`,`after`,created_at)
VALUES (9001,'Fixture','Synthetic correction','{"amount":"30.00"}','{"amount":"25.00"}','2026-11-07');
COMMIT;"""
    sql(fixture, source)
    require(sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='billing_restore_test';").strip() == b'0')
    backup = run(command + ['mysqldump', '-uroot', '--host=127.0.0.1', '--single-transaction', '--routines', '--triggers',
                            '--events', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', source])
    sql('CREATE DATABASE billing_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    sql(backup.decode(), target)
    tables = sql('SHOW TABLES;', source).decode().splitlines()
    for table in tables:
        require(re.fullmatch('[a-z_]+', table))
        query = 'SELECT * FROM `' + table + '` ORDER BY 1;'
        require(hashlib.sha256(sql(query, source)).digest() == hashlib.sha256(sql(query, target)).digest())
    constraints = "SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME;"
    require(sql(constraints, source) == sql(constraints, target))
    require(b'RESTRICT' in sql(constraints, target))
    print('MySQL 8 synthetic full dump/restore, all table rows and financial FK rules: PASS')


if __name__ == '__main__':
    try:
        main(sys.argv[1])
    except Exception:
        print('Isolated restore rehearsal failed; no production target is supported.', file=sys.stderr)
        sys.exit(1)
