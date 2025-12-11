<?php

namespace Drupal\Tests\samlauth\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\user\UserInterface;

class SyncUserRolesTest extends SamlLoginTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'externalauth',
    'samlauth',
    'samlauth_user_roles',
  ];

  /**
   * Data provider for testUserLogin().
   *
   * @return array
   *   - Array with samlauth_user_role config values;
   *   - Role attribute (as either array or string);
   *   - Expected roles for the user to have.
   *   - Optional: hints for user to precreate.
   *     - TRUE/unspecified: precreate user '1',
   *     - FALSE: do not precreate user
   *     - array: precreate user with specified roles.
   *   - Optional: expected warning log.
   */
  public static function providerUserLogin() {
    return [
      // Role adding works with and without mapping from attribute value.
      // Multiple roles can be mapped from one value.
      [
        [],
        'role1',
        ['role1'],
      ],
      [
        [
          'value_map' => [
            ['attribute_value' => 'My Role', 'role_machine_name' => 'role1'],
            ['attribute_value' => 'My Role', 'role_machine_name' => 'role2'],
          ]
        ],
        'My Role',
        ['role1', 'role2'],
      ],
      // Mapping multiple values: the SAML attribute can be an array value or
      // a single string with separator.
      [
        [],
        ['role1', 'role2'],
        ['role1', 'role2'],
      ],
      [
        [
          'value_map' => [
            ['attribute_value' => 'My Role', 'role_machine_name' => 'role1'],
            ['attribute_value' => 'My Role2', 'role_machine_name' => 'role2'],
          ]
        ],
        ['My Role', 'My Role2'],
        ['role1', 'role2'],
      ],
      // Separator works, and spaces around the separator are ignored.
      [
        [
          'saml_attribute_separator' => ',',
          'value_map' => [
            ['attribute_value' => 'My Role', 'role_machine_name' => 'role1'],
            ['attribute_value' => 'My Role2', 'role_machine_name' => 'role2'],
          ]
        ],
        ['My Role, My Role2'],
        ['role1', 'role2'],
      ],
      // Separator must be specified / unknown IDP roles log a warning.
      [
        [
          'value_map' => [
            ['attribute_value' => 'My Role', 'role_machine_name' => 'role1'],
            ['attribute_value' => 'My Role2', 'role_machine_name' => 'role2'],
          ]
        ],
        ['My Role, My Role2'],
        [],
        TRUE,
        'Role <em class="placeholder">My Role, My Role2</em> from IdP is not present in <em class="placeholder">value_map</em> configuration value; role assignment was partially skipped.',
      ],
    [
      [
        'saml_attribute_separator' => ',',
        'value_map' => [
          ['attribute_value' => 'My Role', 'role_machine_name' => 'role1'],
          ['attribute_value' => 'My Role2', 'role_machine_name' => 'roleUNK'],
        ]
      ],
      ['My Role, My Role2'],
      ['role1'],
      TRUE,
      'Unknown/invalid role <em class="placeholder">roleUNK</em> in <em class="placeholder">value_map</em> configuration value; (partially?) skipping role assignment.',
    ],
      // Roles are not added to existing users, depending on config.
      [
        ['only_first_login' => TRUE],
        'role1',
        [],
      ],
      [
        ['only_first_login' => TRUE],
        'role1',
        ['role1'],
        FALSE,
      ],

      // Default assignment of roles.
      [
        ['default_roles' => ['role2', 'role3']],
        'role1',
        ['role1', 'role2', 'role3'],
      ],
      [
        ['default_roles' => ['role2', 'role3']],
        'role1',
        ['role1', 'role2', 'role3'],
        FALSE,
      ],
      // Not for existing users if only_first_login=TRUE.
      [
        ['default_roles' => ['role2', 'role3'], 'only_first_login' => TRUE],
        'role1',
        [],
      ],

      // Base test for user with existing roles: if no role specific settings
      // and no attributes sent, make no changes to the roles.
      [
        // Using these settings,
        [],
        // with this attribute being sent (for adding role),
        '',
        // expect the user to have these roles,
        ['role1'],
        // if they start out with these roles.
        ['role1'],
      ],

      // Unassigning specific roles before syncing/adding others:
      [
        // Unknown configured roles log a warning.
        ['unassign_roles' => ['role2', 'role1', 'role3']],
        '',
        [],
        ['role1', 'role2'],
        'Unknown/invalid role <em class="placeholder">role3</em> in <em class="placeholder">unassign_roles</em> configuration value; skipping part of role (un)assignment.',
      ],
      [
        ['unassign_roles' => ['role2', 'role1']],
        'role1',
        ['role1'],
        ['role1', 'role2'],
      ],
      // Not for existing users if only_first_login=TRUE.
      [
        ['unassign_roles' => ['role1'], 'only_first_login' => TRUE],
        '',
        ['role1', 'role2'],
        ['role1', 'role2'],
      ],
      // Combined addition / removal.
      [
        ['unassign_roles' => ['deleteme', 'delete_and_readd'], 'default_roles' => ['delete_and_readd', 'new_default']],
        '',
        ['delete_and_readd', 'new_default', 'unaffected'],
        ['deleteme', 'unaffected'],
      ],
    ];
  }

  /**
   * Tests role assignment.
   *
   * @covers \Drupal\samlauth_user_roles\EventSubscriber\UserRolesEventSubscriber
   * @dataProvider providerUserLogin
   */
  public function testUserLogin(array $config_values, mixed $saml_attributes, mixed $expected, mixed $precreate_user = TRUE, string $expected_warning = '') {
    $create_roles = $expected;
    if (isset($config_values['default_roles'])) {
      $create_roles = array_merge($create_roles, $config_values['default_roles']);
    }
    if (is_array($precreate_user)) {
      $create_roles = array_merge($create_roles, $precreate_user);
    }
    foreach (array_unique($create_roles) as $create_role) {
      $this->createRole([], $create_role);
    }

    $main_config_values = [];
    if ($precreate_user) {
      // Test behavior during existing user login.
      $this->setupUsers(['1*'], is_array($precreate_user) ? $precreate_user : []);
    }
    else {
      // Test behavior during new user login - which should always be the same
      // as for existing users. Map name field to unique ID to enable creation;
      // details of the mapping are unimportant / tested in the parent class.
      $main_config_values = [
        'create_users' => TRUE,
        'user_name_attribute' => 'U',
        'user_mail_attribute' => '',
      ];
    }
    $this->setupConfig(['saml_attribute' => 'r'] + $config_values, $main_config_values);

    // $saml_attributes is only the value of the role attribute.
    $saml_attributes = [
      'U' => [1],
      'r' => is_array($saml_attributes) ? $saml_attributes : [$saml_attributes],
    ];
    $logged_in_account_or_exception = $this->doTestLogin($saml_attributes, is_string($expected));

    if (is_string($expected)) {
      // Expect a specific exception to be thrown, i.e. fail if there is no
      // exception or if the message differs. ($expected must not be empty.)
      $this->assertSame($expected, $logged_in_account_or_exception);
    }
    else {
      $this->assertTrue($logged_in_account_or_exception instanceof UserInterface);
      sort($expected);
      $actual = $logged_in_account_or_exception->getRoles(TRUE);
      sort($actual);
      $this->assertSame($expected, $actual, "Roles are not synchronized correctly.");
    }

    if ($expected_warning) {
      // Check whether this warning was logged.
      $this->assertTrue($this->testLogger->hasRecordThatPasses(
          static function(array $rec) use ($expected_warning) {
            // Only compare message + log level. Other context info is
            // unimportant. (We could compare $rec['channel'] too.)
            $stored_message = new FormattableMarkup($rec['message'], $rec['context']);
            return ((string) $stored_message === $expected_warning);
          },
          RfcLogLevel::WARNING),
        "Expected warning log: '$expected_warning'. Got: " . json_encode($this->testLogger->recordsByLevel[RfcLogLevel::WARNING]),
      );
      // The previous assert more or less assumes there's only one error
      // message. Change this if ever needed.
      $this->assertTrue(count($this->testLogger->recordsByLevel[RfcLogLevel::WARNING]) < 2, "There's supposed to be only one warning log. Found: " . json_encode($this->testLogger->recordsByLevel[RfcLogLevel::WARNING]));
    }
    else {
      $this->assertArrayNotHasKey(RfcLogLevel::WARNING, $this->testLogger->recordsByLevel, "There are not supposed to be any warning logs. Found: " . json_encode($this->testLogger->recordsByLevel[RfcLogLevel::WARNING] ?? []));
    }
  }

  /**
   * Set up users that exist before login.
   *
   * See parent::setupUser() for specs; in addition, the second parameter adds
   * the given roles to all created users (which should be fine because we are
   * only creating a single user).
   */
  protected function setupUsers(array $user_ids, array $roles = []): void {
    $properties = $roles ? ['roles' => $roles] : [];
    foreach ($user_ids as $id) {
      $this->setupUser($id, $properties);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setupConfig(array $config_values, $main_config_values = []): void {
    parent::setupConfig($main_config_values);

    /** @var \Drupal\Core\Config\Config $config */
    $config = $this->container->get('config.factory')->getEditable('samlauth_user_roles.mapping');
    foreach ($config_values + [
      'only_first_login' => FALSE,
      'unassign_roles' => [],
      'default_roles' => [],
      'saml_attribute' => 'r',
      'saml_attribute_separator' => '',
      'value_map' => [],
    ] as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

}
