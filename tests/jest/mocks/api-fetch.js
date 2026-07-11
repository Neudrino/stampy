const apiFetch = jest.fn();
apiFetch.use = jest.fn();
apiFetch.createNonceMiddleware = jest.fn();

module.exports = apiFetch;
module.exports.default = apiFetch;
