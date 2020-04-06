import setuptools

setuptools.setup(
  name = 'appwrite',
  packages = ['appwrite', 'appwrite/services'],
  version = '0.0.4',
  license='BSD-3-Clause',
  description = 'Appwrite is an open-source backend as a service server that abstract and simplify complex and repetitive development tasks behind a very simple to use REST API. Appwrite aims to help you develop your apps faster and in a more secure way.
                        Use the Python SDK to integrate your app with the Appwrite server to easily start interacting with all of Appwrite backend APIs and tools.
                        For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)',
  author = 'Appwrite Team',
  author_email = 'team@appwrite.io',
  maintainer = 'Appwrite Team',
  maintainer_email = 'team@appwrite.io',
  url = 'https://appwrite.io/support',
  download_url='https://github.com/appwrite/sdk-for-python/archive/0.0.4.tar.gz',
  # keywords = ['SOME', 'MEANINGFULL', 'KEYWORDS'],
  install_requires=[
          'requests',
      ],
  classifiers=[
    'Development Status :: 5 - Production/Stable',
    'Intended Audience :: Developers',
    'Environment :: Web Environment',
    'Topic :: Software Development',
    'License :: OSI Approved :: BSD License',
    'Programming Language :: Python :: 3',
    'Programming Language :: Python :: 3.4',
    'Programming Language :: Python :: 3.5',
    'Programming Language :: Python :: 3.6',
    'Programming Language :: Python :: 3.7',
  ],
)
