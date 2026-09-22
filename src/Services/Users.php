<?php
// ... (other imports and class definition)

class Users
{
    // ... (other methods)

    /**
     * Update user preferences.
     *
     * @param string $userId The user ID.
     * @param array  $prefs  The new preferences.
     *
     * @return User The updated user.
     */
    public function updatePrefs(string $userId, array $prefs): User
    {
        // Retrieve the user.
        $user = $this->getUser($userId);

        // Update the preferences on the user object.
        $user->setPrefs($prefs);

        // Persist the updated user.
        $this->saveUser($user);

        // Trigger the update event with the full user object.
        // Previously only the preferences were sent, causing
        // inconsistent payloads for `users.*.update` triggers.
        $this->triggerEvent('users.update', $user->toArray());

        return $user;
    }

    // ... (other methods)
}
