use Illuminate\Http\Request;

protected $proxies = '*';

protected $headers = Request::HEADER_X_FORWARDED_ALL;