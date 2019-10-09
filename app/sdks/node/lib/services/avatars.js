const Service = require('../service.js');

class Avatars extends Service {

    /**
     * Get Browser Icon
     *
     * /docs/references/avatars/get-browser.md
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
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'width': width,
                'height': height,
                'quality': quality
            });
    }

    /**
     * Get Credit Card Icon
     *
     * /docs/references/avatars/get-credit-cards.md
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
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'width': width,
                'height': height,
                'quality': quality
            });
    }

    /**
     * Get Favicon
     *
     * /docs/references/avatars/get-favicon.md
     *
     * @param string url
     * @throws Exception
     * @return {}
     */
    async getFavicon(url) {
        let path = '/avatars/favicon';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'url': url
            });
    }

    /**
     * Get Country Flag
     *
     * /docs/references/avatars/get-flag.md
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
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'width': width,
                'height': height,
                'quality': quality
            });
    }

    /**
     * Get Image from URL
     *
     * /docs/references/avatars/get-image.md
     *
     * @param string url
     * @param number width
     * @param number height
     * @throws Exception
     * @return {}
     */
    async getImage(url, width = 400, height = 400) {
        let path = '/avatars/image';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'url': url,
                'width': width,
                'height': height
            });
    }

    /**
     * Text to QR Generator
     *
     * /docs/references/avatars/get-qr.md
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
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'text': text,
                'size': size,
                'margin': margin,
                'download': download
            });
    }
}

module.exports = Avatars;