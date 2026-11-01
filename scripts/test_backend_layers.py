import tempfile
from pathlib import Path
import unittest

from backend_layers import violations


class LayerPolicyTest(unittest.TestCase):
    def test_cross_layer_imports_and_hidden_mapper_sql_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            app = root / 'backend-sdui/internal/application'
            app.mkdir(parents=True)
            php = root / 'backend-yii2/repositories'
            php.mkdir(parents=True)
            go = app / 'app.go'
            mapper = php / 'OrderDtoMapper.php'
            go.write_text('package application\nimport "context"\n')
            mapper.write_text('<?php function dto(array $row): array { return $row; }')
            self.assertEqual([], violations(root))
            for imports in ['import "database/sql"', 'import (\n "context"\n yii "shopkmp/backend-sdui/internal/yii"\n)']:
                go.write_text('package application\n' + imports)
                self.assertEqual(1, len(violations(root)))
            mapper.write_text('<?php $db->one("SELECT id FROM orders");')
            self.assertEqual(2, len(violations(root)))


if __name__ == '__main__':
    unittest.main()
