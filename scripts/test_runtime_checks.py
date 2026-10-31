#!/usr/bin/env python3
"""Runtime inventory rejects missing gates, orphan identity services and secret drift."""
import copy
import unittest
import runtime_checks


def containers():
    rows = []
    for name in ('postgres', 'migrate', 'yii-migrate', 'reindex', 'opensearch', 'yii2',
                 'yii2-http', 'sdui', 'maintenance', 'reconcile', 'payment-recovery', 'edge'):
        init = name in ('migrate', 'yii-migrate', 'reindex')
        rows.append({'Config': {'Labels': {'com.docker.compose.service': name}, 'Env': ['DATABASE_URL=secret-never-log']},
                     'Image': 'sha256:fixture', 'HostConfig': {'PortBindings': {'8080/tcp': [{'HostPort': '58095'}]} if name == 'edge' else {}},
                     'State': {'Status': 'exited' if init else 'running', 'Running': not init,
                               'ExitCode': 0, 'Health': {'Status': 'healthy'}}})
    return rows


class RuntimeInventoryTests(unittest.TestCase):
    def test_full_remaining_graph_reports_safe_receipts_only(self):
        result = runtime_checks.check_containers(containers())
        self.assertEqual(12, len(result))
        self.assertEqual('exited', result['reindex']['status'])
        self.assertNotIn('secret-never-log', str(result))

    def test_retired_orphan_environment_missing_worker_and_unhealthy_service_fail(self):
        bad = containers();bad[0]['Config']['Labels']['com.docker.compose.service'] = 'key' + 'cloak'
        env = containers();env[0]['Config']['Env'].append(('KEY' + 'CLOAK_ISSUER') + '=secret-never-log')
        admin = containers();admin[0]['Config']['Env'].append('ADMIN_' + 'EMAILS=secret-never-log')
        unhealthy = containers();unhealthy[4]['State']['Health']['Status'] = 'unhealthy'
        failed_init = containers();failed_init[3]['State']['ExitCode'] = 1
        published = containers();published[0]['HostConfig']['PortBindings'] = {'5432/tcp': [{'HostPort': '5432'}]}
        for rows in (bad, env, admin, containers()[:-2], unhealthy, failed_init, published):
            with self.subTest(case=rows[0]['Config']['Labels']):
                with self.assertRaises(ValueError) as error:
                    runtime_checks.check_containers(rows)
                self.assertNotIn('secret-never-log', str(error.exception))

    def test_running_initialization_is_not_a_successful_exit(self):
        rows = copy.deepcopy(containers())
        rows[3]['State']['Status'] = 'running'
        rows[3]['State']['Running'] = True
        with self.assertRaises(ValueError):
            runtime_checks.check_containers(rows)


if __name__ == '__main__':
    unittest.main(verbosity=2)
