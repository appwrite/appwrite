import { Client, Users } from "@appwrite.io/console";

const client = new Client();
const users = new Users(client);

client.setEndpoint('https://[HOSTNAME_OR_IP]/v1');
client.setProject('5df5acd0d48c2');

const userId = '[USER_ID]';
const customData = { 
  favoriteColor: 'red', 
  favoriteFood: 'pasta'
};

const promise = users.update(userId, {
  customData: customData
});

promise.then(function (response) { 
  console.log(response); // Success 
}, function (error) { 
  console.log(error); // Failure 
});


