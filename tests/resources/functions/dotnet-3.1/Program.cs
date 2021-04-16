using System;
using Appwrite;

namespace dotnet
{
    class Program
    {
        static void Main(string[] args)
        {
            Client client = new Client();

            client.SetEndPoint(Environment.GetEnvironmentVariable("APPWRITE_ENDPOINT"));
            client.SetProject(Environment.GetEnvironmentVariable("APPWRITE_PROJECT"));
            client.SetKey(Environment.GetEnvironmentVariable("APPWRITE_SECRET"));

            Storage storage = new Storage(client);

            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_ID"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_NAME"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_TAG"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_TRIGGER"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_ENV_NAME"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_ENV_VERSION"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_EVENT"));
            Console.WriteLine(Environment.GetEnvironmentVariable("APPWRITE_FUNCTION_EVENT_DATA"));
        }
    }
}
