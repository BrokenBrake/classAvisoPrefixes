<?php
/*
Класс для работы с API коротких номеров AvisoSMS.
Сделал BrokenBrake.biz, отзывы и предложения
оставляйте здесь - 

	P.S. Это мой первый класс! :)

	Внимание! Класс предназначен для работы с номерами
	для России и Украины! Если вам нужна Беларусь, дополняйте
	сами, но тогда придётся учитывать множество исключений.
	У них номера не совпадают с нашими.

Особенность реализации класса: ВСЕ открытые методы
выдают значение (число != 0, массив данных, строка)
или информационное сообщение при нормальных условиях. 
Если хоть какая-то ошибка, метод возвращает False, 
а значение ошибки получаем через метод errorMessage().
Кроме того есть метод errorID, возвращающий числовой
код ошибки (удобно использовать для условий).

Список всех методов (интерфейс класса)

useCache()
	Использовать кэш. Можно передать массив аргументов, в котором будет
	желаемый относительный путь к файлу кэша (file), 
	и/или время его жизни в секундах (live).
	По умолчанию значение file = var/classAvisoPrefixes.cache,
	а live = 86400 (кэш будет обновляться раз в сутки).
	Если вы не используете проверку цены SMS на номер, смысла в кэше нет.
	Естественно, файл кэша должен быть доступен для записи.

errorMessage()
	Получить значение ошибки (False, если ошибок не было).

errorID()
	Получить цифровой код ошибки (аналогично errorMessage).

allNumbers([$coverage])
	Получить массив всех возможных номеров. 
	Для каждого номера такие ключи:
		[costAverage] => средняя цена для пользователя (с НДС)
		[costMax] => максимальная цена (зависит от ОпСоСа)
		[costMin] => минимальная цена
		[coverage] => охват (может быть ru, ua или чаще всего ru-ua)
		[profitAverage] => средний доход вебмастера с принятой SMS
		[profitMax] => максимальный доход
		[profitMin] => минимальный доход
	В метод можно передать необязательный параметр-фильтр $coverage,
	со значениями:
		ru			- выводить только те номера, которые работают в России
		ua			- то же для Украины
		ru-ua		- выводить только те номера, которые подходят для обеих стран
	По-умолчанию выводятся вообще ВСЕ доступные в AvisoSMS номера для
	России и Украины, при этом некоторые номера могут быть только российскими 
	или только украинскими.


numbersAroundCost($sum [, $coverage])
	Получить короткие номера телефонов стоимостью для абонента
	в районе $sum РУБЛЕЙ (с НДС).	Выдаёт массив из двух ближайших номеров, 
	формат массива аналогичен allNumbers + добавляется параметр 
	diff - отличие цены от заданной суммы. 
	Вторым параметром также может передаваться охват (ru, ua, ru-ua).
	
profitFromNumber($number)
	Средняя сумма выплаты вебмастеру (в рублях).

costForNumber($number)
	Формат аналогично profitFromNumber, но выдаёт максимальную для абонента цену.

costFromTo($number)
	Получить строку вида «от N руб. Z коп. до X руб. Y коп.» для номера.
	Естественно, это значения конечных цен для абонента (с НДС).
	Удобно использовать в подсказках абоненту, не обрабатывая результат
	выдачи costForNumber().

checkSignal($signal, $check [, $reply])
	Проверить сигнал от AvisoSMS. Первым аргументе удобно делать массив $_REQUEST,
	во втором массиве вы можете передать любые параметры, которые хотите сравнить.
	Для справки читайте описание API - http://avisosms.ru/development/isnn
	Например, чтобы проверить, что SMS пришла на номер 2420,
	передайте в $check['sn'] значение 2420. В массиве $check должны быть только 
	те	важные для вас параметры, которые вы учитываете. 
	Метод возвращает $reply (OK по умолчанию) или False.

 */

class AvisoPrefixes 
{

	private $useCache = False;
	private $cacheFile = 'var/classAvisoPrefixes.cache';
	private $cacheLive = 86400;
	private $timeout = 5; // сек., макс. время ожидания ответа от сервера
	private $error = False;
	private $errorID = False;
	private $data = False; // массив данных о номерах и ценах



	/*********************
	 * Функция подключения кэша
	 */
	public function useCache($set = '') 
	{
		if (is_array($set)) extract($set); // ключи в переменные
		if (isset($file)) 
		{
			$this->cacheFile = $file;
		}
		if (isset($live)) 
		{
			$this->cacheLive = $live;
		}

		if (file_exists($this->cacheFile)) 
		{
			if ($data = $this->readCache()) 
			{
				$this->useCache = True;
				return 'Файл кэша подключен.';
			}
			else // если не читается 
			{
				return False;
				// ошибка будет от readCache()
			}
		}
		else // файла пока нет? Попробуем создать...
		{
			$data['time'] = time() - ($this->cacheLive + 1); // просрочка
			return $this->writeCache($data);
		}
	} // useCache



	/*********************
	 * Получить массив номеров (всех, или с фильтром)
	 */
	public function allNumbers($coverage = '')
	{
		$this->getData();

		if ($this->data)
		{
			if (empty($coverage))
			{
				$result = $this->data;
			}
			else
			{
				foreach($this->data as $number => $one)
				{
					if (strpos('tmp'.$one['coverage'], $coverage))
						$result[$number] = $one;
				}
			}
			unset($result['time']);
			return $result;
		}
		else
		{
			return False;
		}
	} // allNumbers()



	/*********************
	 * Получить номер(a) для цены
	 */
	private $limit = 2; // сколько номеров в выдаче?
	public function numbersAroundCost($sum, $coverage = '')
	{
		if ((INT)$sum) // Если цена есть, будет True
		{
			$data = $this->allNumbers($coverage);
			if ($data)
			{
				array_walk($data, 
					// Хренасе, тут не только мой первый класс, ещё и первая лямбда-функция! :)
					create_function(
						'&$one', 
						"\$one['diff'] = round(abs(\$one['costAverage'] - $sum));"
										) // create_function
								); // array_walk
				uasort($data, create_function('$one, $two', 'return $one["diff"] - $two["diff"];'));
				$data = array_slice($data, 0, $this->limit, True);
			}
			return $data;
		}
		else
		{
			return $this->error(6);
		}
	} // numbersAroundCost()



	/*********************
	 * Средняя сумма выплаты вебмастеру
	 */
	public function profitFromNumber($number)
	{
		$this->getData();
		if ($this->data)
		{
			return $this->data[$number]['profitAverage'];
		}
		else
		{
			return False;
		}
	} // profitFromNumber()



	/*********************
	 * Получить цену для абонента (максимум, с НДС)
	 */
	public function costForNumber($number)
	{
		$this->getData();
		if ($this->data)
		{
			return $this->data[$number]['costMax'];
		}
		else
		{
			return False;
		}
	}



	/*********************
	 * Получить строку вида «от N руб. Z коп. до X руб. Y коп.» для номера
	 */
	public function costFromTo($number)
	{
		$this->getData();
		if ($this->data)
		{
			$max = explode('.', $this->data[$number]['costMax']);
			$min = explode('.', $this->data[$number]['costMin']);

			$kopFn = create_function('$num', 
				'
					$num = (INT)$num;
					if ($num)
						return " $num"." коп.";
					else return \'\';
				'); // лямбдочка :) По-моему, довольно уродливо... но прикольно

			$minKop = $kopFn($min[1]);
			$maxKop = $kopFn($max[1]);

			return "от {$min[0]} руб.$minKop до {$max[0]} руб.$maxKop";
		}
		else
		{
			return False;
		}
	}



	/*********************
	 * Проверить сигнал от AvisoSMS
	 */
	public function checkSignal($signal, $check, $reply = 'OK')
	{
		if (is_array($check) AND is_array($signal))
		{
			foreach ($check as $key => $val)
			{
				@++$ID;
				if (isset($signal[$key]))
				{
					if ($val !== $signal[$key])
					{
						$reply = $this->error(100 + $ID, "Неверное значение параметра $key: {$signal[$key]} вместо $val.");
					}
				}
				else
				{
					$reply = $this->error((100 + $ID), "В запросе нет проверяемого параметра $key.");
				}
			}
			return $reply;
		}
		else
		{
			return $this->error(7);
		}
	}



	/*********************
	 * Функция чтения кэша
	 */
	private function readCache()
	{
		if (is_readable($this->cacheFile))
		{
			$data = trim(file_get_contents($this->cacheFile));
			if (!empty($data))
			{
				$data = unserialize($data);
			}
			else // Файл пустой
			{
				return $this->error(3);
			}

			if (isset($data['time']))
			{
				return $data;
			}
			else // что-то явно не так, если нет метки времени
			{
				return $this->error(1);
			}
		}
		else // нет доступа к файлу 
		{
			return $this->error(2);
		}
	} // readCache()



	/*********************
	 * Сериализация данных и запись без всяких проверок
	 */
	private function justWrite($data) // попытка записи без всяких проверок
	{
		// Собачка! Потому что ошибку доступа обрабатывает класс
		return @file_put_contents($this->cacheFile, serialize($data));
	}



	/*********************
	 * Функция записи кэша
	 */
	private function writeCache($data)
	{
		$OK = "Произведена запись в файл кэша {$this->cacheFile}."; 

		if (file_exists($this->cacheFile))
		{
			if (is_writable($this->cacheFile))
			{
				$this->justWrite($data);
				return $OK;
			}
			else // нет доступа к файлу
			{
				return $this->error(2);
			}
		}
		else // Нет файла
		{
			if ($this->justWrite($data))
			{
				return $OK;
			}
			else // не вышло записать (нет доступа)
			{
				return $this->error(2);
			}
		}
	} // writeCache()



	/*********************
	 * Загрузка данных с внешнего URL + таймаут
	 */
	private function fetchData($url)
	{
		$ctx = stream_context_create(array
			('http' => array('timeout' => $this->timeout)));
		if ($result = @file_get_contents($url, 0, $ctx))
		{
			return $result;
		}
		else 
		{
			return $this->error(4);
		}
	} // fetchData()



	/*********************
	 * Загрузить трубу (обёртка для API Aviso) и обработать данные
	 * Самый спорный метод... но вроде и разделять на два нет смысла?
	 */
	private function getPipe()
	{
		if ($data = $this->fetchData('http://pipes.yahoo.com/pipes/' // длинно!
			.'pipe.run?_id=fefcd7d96c177e02cb6194b0159c35e3&_render=php'))
		{
			$data = unserialize($data);
			if (isset($data['value']['items']['100'])) // значит массив номеров есть
			{
				foreach ($data['value']['items'] as $one)
				{
					$number = $one['phone'];

					$new[$number]['costMax'] = max(@$new[$number]['costMax'], $one['cost_nds']);
					$new[$number]['profitMax'] = max(@$new[$number]['profitMax'], $one['share']);

					// Две строки ниже необходимы, иначе после min() всегда был бы 0
					if (!isset($new[$number]['costMin'])) $new[$number]['costMin'] = 1000;
					if (!isset($new[$number]['profitMin'])) $new[$number]['profitMin'] = 1000;

					$new[$number]['costMin'] = min($new[$number]['costMin'], $one['cost_nds']);
					$new[$number]['profitMin'] = min($new[$number]['profitMin'], $one['share']);

					@++$new['tmp'][$number]['count'];
					@$new['tmp'][$number]['costSum'] += $one['cost_nds'];
					@$new['tmp'][$number]['profitSum'] += $one['share'];
					$tmp = $new['tmp'][$number];

					$new[$number]['costAverage'] = round($tmp['costSum'] / $tmp['count'], 2);
					$new[$number]['profitAverage'] = round($tmp['profitSum'] / $tmp['count'], 2);

					// Охват (страны: ru, ua)
					$cn = $one['cn'];
					@$new['tmp'][$number]['cn'][] = $cn;
					sort($new['tmp'][$number]['cn']);
					$cn = array_unique($new['tmp'][$number]['cn']);
					//$cn = $new['tmp'][$number]['cn'];
					$new[$number]['coverage'] = join('-', $cn);

					unset($tmp, $cn, $one['title'], $one['description'], $one['phone']); // мусор
					ksort($new[$number]); // Необязательно, но так аккуратней :)
					// Если раскомментировать, тогда в базе будут все операторы
					//$new[$number]['x_opsos'][] = $one;
				}
				unset($data, $new['tmp']);
				$data = $new;
				$data['time'] = time();
			}
			else // если корявая трубка
			{
				$data = $this->error(5);
			}
		}
		return $data;
	} // getPipe()



	/*********************
	 * Получить данные (и записать кэш, если он протух, или нулевой)
	 */
	private function getData()
	{
		$goPipe = False;
		$writeCache = False;

		if (!$this->data) // а если данные уже есть в объекте, то ничего не делаем 
		{
			if ($this->useCache)
			{
				$data = $this->readCache();
				if (
					$data['time'] + $this->cacheLive < time()
					OR count($data) < 5 // это ж странно, если номеров там так мало?
				)
				{
					$goPipe = True;
					$writeCache = True;
				}
			}
			else // как же ж, почему ж?! Используйте кэш! :)
			{
				$goPipe = True;
			}

			if ($goPipe) 
			{
				$data = $this->getPipe();
			}
			$this->data = $data;

			if ($writeCache AND $data)
			{
				$this->writeCache($data);
			}
			unset($data); // экономия на спичках?
		}
	}



	/*********************
	 * Вывод сообщения об ошибке
	 */
	public function errorMessage()
	{
		return $this->error;
	}



	/*********************
	 * Вывод цифрового кода ошибки
	 */
	public function errorID()
	{
		return $this->errorID;
	}



	/*********************
	 * Обработка ошибок по коду
	 */
	private function error($ID, $message = '')
	{
		// 11.10.10: Сделать константы! Для динамического вывода constant();
		// 21.10.10: Не, константы PHP в глобальной области видимости...
		$err[1] = 'Файл кэша повреждён, отсутствует метка времени.';
		$err[2] = "Нет доступа к файлу кэша ({$this->cacheFile}).";
		$err[3] = 'Файл кэша почему-то пустой.';
		$err[4] = "Не удалось соединиться с внешним сервером за {$this->timeout} сек. (timeout)";
		$err[5] = 'Yahoo Pipes выдали пустую или неправильную трубу.';
		$err[6] = 'Не указана цена в numbersAroundCost() для поиска номера по цене.';
		$err[7] = 'Должен быть сигнал и массив параметров для проверки.';

		// Вот это добавил в последний момент при конструировании checkSignal()
		// 11.11.2010
		// Не лучший вариант? А как?
		if (!isset($err[$ID]))
		{
			$err[$ID] = $message;
		}

		$this->error = "{$err[$ID]} \nКод ошибки $ID";
		$this->errorID = $ID;
		return False; // Всегда возвращает False
	} // $this->error()

} // class AvisoPrefixes

//header('Content-Type: text/plain; charset=UTF-8');
//$test = new AvisoPrefixes;
