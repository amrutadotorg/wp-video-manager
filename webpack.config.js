const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');

module.exports = {
    entry: './src/video-chapters.js', // Entry point for your JavaScript
    output: {
        filename: 'video-chapters.min.js', // Output JavaScript file
        path: path.resolve(__dirname, 'dist'), // Output directory
        clean: true, // Clean the output directory before each build
    },
    module: {
        rules: [
            {
                test: /\.js$/, // Match JavaScript files
                exclude: /node_modules/, // Exclude dependencies
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env'], // Use Babel for modern JS
                    },
                },
            },
            {
                test: /\.css$/, // Match CSS files
                use: [
                    MiniCssExtractPlugin.loader, // Extract CSS into a separate file
                    'css-loader', // Translates CSS into CommonJS
                ],
            },
        ],
    },
    plugins: [
        new MiniCssExtractPlugin({
            filename: 'video-chapters.min.css', // Output CSS file
        }),
    ],
    optimization: {
        minimize: true, // Enable code minimization
        minimizer: [
            new TerserPlugin(), // Minimize JavaScript
            new CssMinimizerPlugin(), // Minimize CSS
        ],
    },
    devtool: 'source-map', // Generate source maps for debugging
    mode: 'production', // Set Webpack to production mode
};
