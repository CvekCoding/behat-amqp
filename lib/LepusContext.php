<?php

namespace Cvek\BehatAmqp;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Sci\Assert\Assert;

class LepusContext implements Context
{
    /** @var AMQPStreamConnection */
    private $connection;

    /** @var AMQPChannel */
    private $channel;

    /**
     * @param string $host
     * @param int $port
     * @param string $user
     * @param string $password
     * @param string $vhost
     */
    public function init($host, $port, $user, $password, $vhost)
    {
        $this->connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
        $this->channel = $this->connection->channel();
    }

    /**
     * @Given there is a queue :queue
     */
    public function thereIsAQueue($queue)
    {
        $this->channel->queue_declare($queue, false, true, false, false);
    }

    /**
     * @Given there is an exchange :exchange
     */
    public function thereIsAnExchange($exchange)
    {
        $this->channel->exchange_declare($exchange, 'direct');
    }

    /**
     * @Given the queue :queue is empty
     */
    public function theQueueIsEmpty($queue)
    {
        try {
            $this->channel->queue_purge($queue);
        } catch (\Exception $e) {
            $this->channel = $this->connection->channel();
        }
    }

    /**
     * @When I send a message to queue :queue
     */
    public function iSendAMessageToQueue($queue, PyStringNode $string)
    {
        $message = new AMQPMessage($string->getRaw());

        $this->connection->channel()->basic_publish($message, '', $queue);
    }

    /**
     * @When I send a message to exchange :queue with key :key
     */
    public function iSendAMessageToExchangeWithKey($exchange, string $key, PyStringNode $string)
    {
        $message = new AMQPMessage($string->getRaw());

        $this->connection->channel()->basic_publish($message, $exchange, $key);
    }

    /**
     * @Then there should be a message in queue :queue
     *
     * @param              $queue
     * @param PyStringNode $string
     *
     * @throws \ErrorException
     */
    public function thereShouldBeAMessageInQueue($queue, PyStringNode $string = null)
    {
        $this->connection->reconnect();
        $this->channel = $this->connection->channel();

        $expected = $string ? $string->getRaw() : null;

        if (null === $expected) {
            $this->channel->basic_consume($queue);
        } else {
            $consumer = function (AMQPMessage $message) use ($expected) {
                $this->channel->basic_ack($message->delivery_info['delivery_tag']);

                Assert::that($message->body)->equal($expected);
            };
            $this->channel->basic_consume($queue, '', false, true, false, false, $consumer);
        }
        $this->channel->wait(null, false, 4);
    }

    /**
     * @Given queue :queue bound to exchange :exchange with :key
     * @Given queue :queue bound to exchange :exchange
     */
    public function queueBoundToExchange($queue, $exchange, $key = null)
    {
        $this->channel->queue_bind($queue, $exchange, $key ?? $queue);
    }

    /**
     * @Then the message in queue :queue contains
     *
     * @param              $queue
     * @param PyStringNode $string
     *
     * @throws \ErrorException
     */
    public function theMessageInQueueContains($queue, PyStringNode $string = null)
    {
        $this->connection->reconnect();
        $this->channel = $this->connection->channel();

        $expected = $string ? $string->getRaw() : null;

        if (null === $expected) {
            $this->channel->basic_consume($queue);
        } else {
            $consumer = function (AMQPMessage $message) use ($expected) {
                $this->channel->basic_ack($message->delivery_info['delivery_tag']);

                if (0 === substr_count($message->getBody(), $expected)) {
                    throw new \InvalidArgumentException(sprintf('Substring %s was not found in %s', $expected, $message->getBody()));
                }
            };
            $this->channel->basic_consume($queue, '', false, true, false, false, $consumer);
        }
        $this->channel->wait(null, false, 4);
    }
}
