package io.appwrite.android.ui.accounts

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.databinding.DataBindingUtil
import androidx.fragment.app.Fragment
import androidx.lifecycle.Observer
import androidx.lifecycle.ViewModelProvider
import io.appwrite.android.R
import io.appwrite.android.databinding.FragmentAccountBinding


class AccountsFragment : Fragment() {

    private lateinit var binding: FragmentAccountBinding
    private lateinit var viewModel: AccountsViewModel

    override fun onCreateView(
        inflater: LayoutInflater ,
        container: ViewGroup? ,
        savedInstanceState: Bundle?
    ): View? {
        viewModel = ViewModelProvider(this).get(AccountsViewModel::class.java)
        binding = DataBindingUtil.inflate(
            inflater,
            R.layout.fragment_account,
            container,
            false
        )
        binding.lifecycleOwner = viewLifecycleOwner
        binding.login.setOnClickListener{
            viewModel.onLogin(binding.email.text, binding.password.text)
        }

        binding.signup.setOnClickListener{
            viewModel.onSignup(binding.email.text, binding.password.text, binding.name.text)
        }

        binding.getUser.setOnClickListener{
            viewModel.getUser()
        }

        binding.oAuth.setOnClickListener{
            viewModel.oAuthLogin(activity as ComponentActivity)
        }

        binding.logout.setOnClickListener{
            viewModel.logout()
        }

        viewModel.error.observe(viewLifecycleOwner, Observer { event ->
            event?.getContentIfNotHandled()?.let { // Only proceed if the event has never been handled
                Toast.makeText(requireContext(), it.message , Toast.LENGTH_SHORT).show()
            }
        })

        viewModel.response.observe(viewLifecycleOwner, Observer { event ->
            event?.getContentIfNotHandled()?.let {
                binding.responseTV.setText(it)
            }
        })

        return binding.root
    }
}