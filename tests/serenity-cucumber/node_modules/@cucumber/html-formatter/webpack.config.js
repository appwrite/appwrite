const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: './dist/src/main.js',
  module: {
    rules: [
      {
        test: /\.scss$/,
        use: [
          MiniCssExtractPlugin.loader,
          {
            loader: 'css-loader',
            options: {
              modules: {
                auto: true
              }
            }
          },
          'sass-loader'
        ],
      }
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: 'main.css'
    })
  ]
};
