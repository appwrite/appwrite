package io.appwrite.android.ui.accounts

import android.text.Editable
import androidx.activity.ComponentActivity
import androidx.lifecycle.*
import io.appwrite.android.utils.Client.client
import io.appwrite.android.utils.Event
import io.appwrite.exceptions.AppwriteException
import io.appwrite.services.Account
import kotlinx.coroutines.launch
import org.json.JSONObject


class AccountsViewModel : ViewModel() {

    private val _error = MutableLiveData<Event<Exception>>().apply {
        value = null
    }
    val error: LiveData<Event<Exception>> = _error

    private val _response = MutableLiveData<Event<String>>().apply {
        value = null
    }
    val response: LiveData<Event<String>> = _response

    private val accountService by lazy {
        Account(client)
    }

    fun onLogin(email: Editable , password : Editable) {
        viewModelScope.launch {
            try {
                var response = accountService.createSession(email.toString(), password.toString())
                var json = response.body?.string() ?: ""
                json = JSONObject(json).toString(8)
                _response.postValue(Event(json))
            } catch (e: AppwriteException) {
                _error.postValue(Event(e))
            }
        }

    }

    fun onSignup(email: Editable , password : Editable, name: Editable) {
        viewModelScope.launch {
            try {
                var response = accountService.create(email.toString(), password.toString(), name.toString())
                var json = response.body?.string() ?: ""
                json = JSONObject(json).toString(2)
                _response.postValue(Event(json))
            } catch (e: AppwriteException) {
                _error.postValue(Event(e))
            }
        }

    }

    fun oAuthLogin(activity: ComponentActivity) {
        viewModelScope.launch {
            try {
                accountService.createOAuth2Session(activity, "facebook", "appwrite-callback-6070749e6acd4://demo.appwrite.io/auth/oauth2/success", "appwrite-callback-6070749e6acd4://demo.appwrite.io/auth/oauth2/failure")
            } catch (e: Exception) {
                _error.postValue(Event(e))
            } catch (e: AppwriteException) {
                _error.postValue(Event(e))
            }
        }
    }

    fun getUser() {
        viewModelScope.launch {
            try {
                var response = accountService.get()
                var json = response.body?.string() ?: ""
                json = JSONObject(json).toString(2)
                _response.postValue(Event(json))
            } catch (e: AppwriteException) {
                _error.postValue(Event(e))
            }
        }
    }

    fun logout() {
        viewModelScope.launch {
            try {
                var response = accountService.deleteSession("current")
                var json = response.body?.string()?.ifEmpty { "{}" }
                json = JSONObject(json).toString(4)
                _response.postValue(Event(json))
            } catch (e: AppwriteException) {
                _error.postValue(Event(e))
            }
        }
    }

}