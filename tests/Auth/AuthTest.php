<?php

use BookStack\EmailConfirmation;

class AuthTest extends TestCase
{

    public function test_auth_working()
    {
        $this->visit('/')
            ->seePageIs('/login');
    }

    public function test_login()
    {
        $this->login('admin@admin.com', 'password')
            ->seePageIs('/');
    }

    public function test_public_viewing()
    {
        $settings = app('BookStack\Services\SettingService');
        $settings->put('app-public', 'true');
        $this->visit('/')
            ->seePageIs('/')
            ->see('Sign In');
    }

    public function test_registration_showing()
    {
        // Ensure registration form is showing
        $this->setSettings(['registration-enabled' => 'true']);
        $this->visit('/login')
            ->see('Sign up')
            ->click('Sign up')
            ->seePageIs('/register');
    }

    public function test_normal_registration()
    {
        // Set settings and get user instance
        $this->setSettings(['registration-enabled' => 'true']);
        $user = factory(\BookStack\User::class)->make();

        // Test form and ensure user is created
        $this->visit('/register')
            ->see('Sign Up')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/')
            ->see($user->name)
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email]);
    }


    public function test_confirmed_registration()
    {
        // Set settings and get user instance
        $this->setSettings(['registration-enabled' => 'true', 'registration-confirmation' => 'true']);
        $user = factory(\BookStack\User::class)->make();

        // Mock Mailer to ensure mail is being sent
        $mockMailer = Mockery::mock('Illuminate\Contracts\Mail\Mailer');
        $mockMailer->shouldReceive('send')->with('emails/email-confirmation', Mockery::type('array'), Mockery::type('callable'))->twice();
        $this->app->instance('mailer', $mockMailer);

        // Go through registration process
        $this->visit('/register')
            ->see('Sign Up')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register/confirm')
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email, 'email_confirmed' => false]);

        // Test access and resend confirmation email
        $this->login($user->email, $user->password)
            ->seePageIs('/register/confirm/awaiting')
            ->see('Resend')
            ->visit('/books')
            ->seePageIs('/register/confirm/awaiting')
            ->press('Resend Confirmation Email');

        // Get confirmation
        $user = $user->where('email', '=', $user->email)->first();
        $emailConfirmation = EmailConfirmation::where('user_id', '=', $user->id)->first();


        // Check confirmation email button and confirmation activation.
        $this->visit('/register/confirm/' . $emailConfirmation->token . '/email')
            ->see('Email Confirmation')
            ->click('Confirm Email')
            ->seePageIs('/')
            ->see($user->name)
            ->notSeeInDatabase('email_confirmations', ['token' => $emailConfirmation->token])
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email, 'email_confirmed' => true]);
    }

    public function test_restricted_registration()
    {
        $this->setSettings(['registration-enabled' => 'true', 'registration-confirmation' => 'true', 'registration-restrict' => 'example.com']);
        $user = factory(\BookStack\User::class)->make();
        // Go through registration process
        $this->visit('/register')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register')
            ->dontSeeInDatabase('users', ['email' => $user->email])
            ->see('That email domain does not have access to this application');

        $user->email = 'barry@example.com';

        $this->visit('/register')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->type($user->password, '#password')
            ->press('Create Account')
            ->seePageIs('/register/confirm')
            ->seeInDatabase('users', ['name' => $user->name, 'email' => $user->email, 'email_confirmed' => false]);
    }

    public function test_user_creation()
    {
        $user = factory(\BookStack\User::class)->make();

        $this->asAdmin()
            ->visit('/settings/users')
            ->click('Add new user')
            ->type($user->name, '#name')
            ->type($user->email, '#email')
            ->check('roles[admin]')
            ->type($user->password, '#password')
            ->type($user->password, '#password-confirm')
            ->press('Save')
            ->seePageIs('/settings/users')
            ->seeInDatabase('users', $user->toArray())
            ->see($user->name);
    }

    public function test_user_updating()
    {
        $user = \BookStack\User::all()->last();
        $password = $user->password;
        $this->asAdmin()
            ->visit('/settings/users')
            ->click($user->name)
            ->seePageIs('/settings/users/' . $user->id)
            ->see($user->email)
            ->type('Barry Scott', '#name')
            ->press('Save')
            ->seePageIs('/settings/users')
            ->seeInDatabase('users', ['id' => $user->id, 'name' => 'Barry Scott', 'password' => $password])
            ->notSeeInDatabase('users', ['name' => $user->name]);
    }

    public function test_user_password_update()
    {
        $user = \BookStack\User::all()->last();
        $userProfilePage = '/settings/users/' . $user->id;
        $this->asAdmin()
            ->visit($userProfilePage)
            ->type('newpassword', '#password')
            ->press('Save')
            ->seePageIs($userProfilePage)
            ->see('Password confirmation required')

            ->type('newpassword', '#password')
            ->type('newpassword', '#password-confirm')
            ->press('Save')
            ->seePageIs('/settings/users');

            $userPassword = \BookStack\User::find($user->id)->password;
            $this->assertTrue(Hash::check('newpassword', $userPassword));
    }

    public function test_user_deletion()
    {
        $userDetails = factory(\BookStack\User::class)->make();
        $user = $this->getNewUser($userDetails->toArray());

        $this->asAdmin()
            ->visit('/settings/users/' . $user->id)
            ->click('Delete User')
            ->see($user->name)
            ->press('Confirm')
            ->seePageIs('/settings/users')
            ->notSeeInDatabase('users', ['name' => $user->name]);
    }

    public function test_user_cannot_be_deleted_if_last_admin()
    {
        $adminRole = \BookStack\Role::getRole('admin');
        // Ensure we currently only have 1 admin user
        $this->assertEquals(1, $adminRole->users()->count());
        $user = $adminRole->users->first();

        $this->asAdmin()->visit('/settings/users/' . $user->id)
            ->click('Delete User')
            ->press('Confirm')
            ->seePageIs('/settings/users/' . $user->id)
            ->see('You cannot delete the only admin');
    }

    public function test_logout()
    {
        $this->asAdmin()
            ->visit('/')
            ->seePageIs('/')
            ->visit('/logout')
            ->visit('/')
            ->seePageIs('/login');
    }

    /**
     * Perform a login
     * @param string $email
     * @param string $password
     * @return $this
     */
    protected function login($email, $password)
    {
        return $this->visit('/login')
            ->type($email, '#email')
            ->type($password, '#password')
            ->press('Sign In');
    }
}
