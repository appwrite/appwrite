package io.appwrite.android

import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import androidx.fragment.app.add
import androidx.fragment.app.commit
import io.appwrite.android.ui.accounts.AccountsFragment
import io.appwrite.android.utils.Client

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        Client.create(applicationContext)

        if (savedInstanceState == null) {
            supportFragmentManager.commit {
                setReorderingAllowed(true)
                add<AccountsFragment>(R.id.fragment_container_view)
            }
        }
    }
}