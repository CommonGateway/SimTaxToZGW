# CommonGateway\SimTaxToZGWBundle\Service\SimTaxService  







## Methods

| Name | Description |
|------|-------------|
|[__construct](#simtaxservice__construct)||
|[createResponse](#simtaxservicecreateresponse)|Creates a response based on content.|
|[simTaxHandler](#simtaxservicesimtaxhandler)|An example handler that is triggered by an action.|




### SimTaxService::__construct  

**Description**

```php
 __construct (void)
```

 

 

**Parameters**

`This function has no parameters.`

**Return Values**

`void`


<hr />


### SimTaxService::createResponse  

**Description**

```php
public createResponse (array $content, int $status)
```

Creates a response based on content. 

 

**Parameters**

* `(array) $content`
: The content to incorporate in the response  
* `(int) $status`
: The status code of the response  

**Return Values**

`\Response`




<hr />


### SimTaxService::simTaxHandler  

**Description**

```php
public simTaxHandler (array $data, array $configuration)
```

An example handler that is triggered by an action. 

 

**Parameters**

* `(array) $data`
: The data array  
* `(array) $configuration`
: The configuration array  

**Return Values**

`array`

> A handler must ALWAYS return an array


<hr />

