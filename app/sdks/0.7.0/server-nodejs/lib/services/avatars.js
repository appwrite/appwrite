const Service = require('../service.js');

class Avatars extends Service {

    /**
     * Get Browser Icon
     *
     * You can use this endpoint to show different browser icons to your users.
     * The code argument receives the browser code as it appears in your user
     * /account/sessions endpoint. Use width, height and quality arguments to
     * change the output settings.
     *
     * @param string code
     * @param number width
     * @param number height
     * @param number quality
     * @throws Exception
     * @return {}
     */
    async getBrowser(code, width = 100, height = 100, quality = 100) {
        let path = '/avatars/browsers/{code}'.replace(new RegExp('{code}', 'g'), code);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'width': width,
                'height': height,
                'quality': quality
            });
    }

    /**
     * Get Credit Card Icon
     *
     * Need to display your users with your billing method or their payment
     * methods? The credit card endpoint will return you the icon of the credit
     * card provider you need. Use width, height and quality arguments to change
     * the output settings.
     *
     * @param string code
     * @param number width
     * @param number height
     * @param number quality
     * @throws Exception
     * @return {}
     */
    async getCreditCard(code, width = 100, height = 100, quality = 100) {
        let path = '/avatars/credit-cards/{code}'.replace(new RegExp('{code}', 'g'), code);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'width': width,
                'height': height,
                'quality': quality
            });
    }

    /**
     * Get Favicon
     *
     * Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
     * website URL.
     *
     * @param string url
     * @throws Exception
     * @return {}
     */
    async getFavicon(url) {
        let path = '/avatars/favicon';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'url': url
            });
    }

    /**
     * Get Country Flag
     *
     * You can use this endpoint to show different country flags icons to your
     * users. The code argument receives the 2 letter country code. Use width,
     * height and quality arguments to change the output settings.
     *
     * @param string code
     * @param number width
     * @param number height
     * @param number quality
     * @throws Exception
     * @return {}
     */
    async getFlag(code, width = 100, height = 100, quality = 100) {
        let path = '/avatars/flags/{code}'.replace(new RegExp('{code}', 'g'), code);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'width': width,
                'height': height,
                'quality': quality
            });
    }

    /**
     * Get Image from URL
     *
     * Use this endpoint to fetch a remote image URL and crop it to any image size
     * you want. This endpoint is very useful if you need to crop and display
     * remote images in your app or in case you want to make sure a 3rd party
     * image is properly served using a TLS protocol.
     *
     * @param string url
     * @param number width
     * @param number height
     * @throws Exception
     * @return {}
     */
    async getImage(url, width = 400, height = 400) {
        let path = '/avatars/image';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'url': url,
                'width': width,
                'height': height
            });
    }

    /**
     * Get QR Code
     *
     * Converts a given plain text to a QR code image. You can use the query
     * parameters to change the size and style of the resulting image.
     *
     * @param string text
     * @param number size
     * @param number margin
     * @param number download
     * @throws Exception
     * @return {}
     */
    async getQR(text, size = 400, margin = 1, download = 0) {
        let path = '/avatars/qr';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'text': text,
                'size': size,
                'margin': margin,
                'download': download
            });
    }
}

module.exports = Avatars;